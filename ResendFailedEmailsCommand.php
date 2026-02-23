<?php

declare(strict_types=1);

namespace App\Api\Command;

use App\Api\Entity\Exemption;
use App\Api\Enum\ExemptionStatus;
use App\Api\Messenger\Message\Email\ExemptionRejectedToEmployee;
use App\Api\Messenger\Message\Email\ExemptionSendUpdateLink;
use App\Api\Messenger\Message\Email\ExemptionValidatedToEmployee;
use App\Api\Messenger\MessageHandler\Email\ExemptionRejectedToEmployeeHandler;
use App\Api\Messenger\MessageHandler\Email\ExemptionSendUpdateLinkHandler;
use App\Api\Messenger\MessageHandler\Email\ExemptionValidatedToEmployeeHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ONE-TIME command — Resends emails (might be used in case of mailer issues).
 *
 * Three possible usages:
 *  - renewal   : sends an email to an employeeincluding a link to exemption renewal form and extends the expiration delay of 1 month.
 *  - validated : sends a notification to an employee to notify about exemption approval.
 *  - rejected  : sends a notification to an employee to notify about exemption rejection.
 *
 * Usage examples:
 *   # Dry-run for a specific outage range
 *   bin/console app:resend-emails --from="2026-01-27 00:00:00" --to="2026-01-28 23:59:59" --dry-run
 *
 *   # Only renewals, explicit IDs
 *   bin/console app:resend-emails --ids="12667,12668" --types=renewal
 *
 *   # Everything in the range, for real
 *   bin/console app:resend-emails --from="2026-01-27 00:00:00" --to="2026-01-28 23:59:59"
 */
class ResendFailedEmailsCommand extends Command
{
    protected static $defaultName = 'app:resend-emails';

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var ExemptionSendUpdateLinkHandler
     */
    private $sendUpdateLinkHandler;

    /**
     * @var ExemptionValidatedToEmployeeHandler
     */
    private $validatedToEmployeeHandler;

    /**
     * @var ExemptionRejectedToEmployeeHandler
     */
    private $rejectedToEmployeeHandler;

    public function __construct(
        EntityManagerInterface $entityManager,
        ExemptionSendUpdateLinkHandler $sendUpdateLinkHandler,
        ExemptionValidatedToEmployeeHandler $validatedToEmployeeHandler,
        ExemptionRejectedToEmployeeHandler $rejectedToEmployeeHandler
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->sendUpdateLinkHandler = $sendUpdateLinkHandler;
        $this->validatedToEmployeeHandler = $validatedToEmployeeHandler;
        $this->rejectedToEmployeeHandler = $rejectedToEmployeeHandler;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Resend emails (useful in case of mailer outage).')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start of the outage range (Y-m-d H:i:s). Required unless --ids is used.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End of the outage range (Y-m-d H:i:s). Defaults to now.')
            ->addOption('ids', null, InputOption::VALUE_REQUIRED, 'Comma-separated exemption IDs to process directly (bypasses date range).')
            ->addOption('types', null, InputOption::VALUE_REQUIRED, 'Comma-separated types to process: renewal,validated,rejected,init. Default: all.', 'renewal,validated,rejected,init')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without sending emails or modifying data.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = (bool) $input->getOption('dry-run');
        $types = array_map('trim', explode(',', (string) $input->getOption('types')));
        $idsRaw = $input->getOption('ids');

        if ($isDryRun) {
            $io->note('DRY-RUN: aucun email ne sera envoyé et aucune donnée ne sera modifiée.');
        }

        // --- Resolve scope: explicit IDs or date range ---
        $explicitIds = [];
        $from = null;
        $to = null;

        if ($idsRaw) {
            if ($input->getOption('from')) {
                $io->error('Provide either --from (date range mode) or --ids (explicit IDs mode) but not both.');

                return 1;
            }

            $explicitIds = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
            $io->note(sprintf('Traitement %d identifiants(s): %s', count($explicitIds), implode(', ', $explicitIds)));
        } else {
            $fromStr = $input->getOption('from');
            if (!$fromStr) {
                $io->error('Provide either --from (date range mode) or --ids (explicit IDs mode).');

                return 1;
            }
            $from = new \DateTimeImmutable($fromStr);
            $to = $input->getOption('to') ? new \DateTimeImmutable((string) $input->getOption('to')) : new \DateTimeImmutable();
            $io->note(sprintf('Plage: %s → %s', $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')));

            if ($from->getTimestamp() > $to->getTimestamp()) {
                $io->error('Invalid date range supplied.');

                return 1;
            }
        }

        $totals = ['renewal' => 0, 'validated' => 0, 'rejected' => 0, 'init' => 0, 'errors' => 0];

        // =====================================================================
        // RENEWAL — to_finalise exemptions created by the auto-renew process
        // Identified by previousReason IS NOT NULL (set by Exemption::withStatus())
        // DeadlineDate is pushed to +1 month from now so the token stays valid.
        // =====================================================================
        if (in_array('renewal', $types, true)) {
            $exemptions = $explicitIds
                ? $this->findRenewalByIds($explicitIds)
                : $this->findRenewalByDateRange($from, $to);

            $io->section(sprintf('Mail de renouvellement (%d trouvés)', count($exemptions)));

            foreach ($exemptions as $exemption) {
                $newDeadline = new \DateTimeImmutable('+1 month');
                $io->writeln(sprintf(
                    '  [#%d] %s %s <%s> | date limite: %s → %s',
                    $exemption->getId(),
                    $exemption->getFirstName(),
                    $exemption->getLastName(),
                    $exemption->getEmail(),
                    $exemption->getDeadlineDate() ? $exemption->getDeadlineDate()->format('Y-m-d') : 'null',
                    $newDeadline->format('Y-m-d')
                ));

                if ($isDryRun) {
                    ++$totals['renewal'];
                    continue;
                }

                try {
                    $exemption->setDeadlineDate($newDeadline);
                    $this->entityManager->persist($exemption);
                    $this->entityManager->flush();

                    $this->sendUpdateLinkHandler->handle(new ExemptionSendUpdateLink($exemption->getId()));
                    ++$totals['renewal'];
                } catch (\Throwable $e) {
                    $io->error(sprintf('Erreur sur #%d: %s', $exemption->getId(), $e->getMessage()));
                    ++$totals['errors'];
                }
            }
        }

        // =====================================================================
        // VALIDATED — exemptions approved during the outage
        // =====================================================================
        if (in_array('validated', $types, true)) {
            $exemptions = $explicitIds
                ? $this->findByStatusAndIds(ExemptionStatus::VALIDATED, $explicitIds)
                : $this->findByStatusAndDateRange(ExemptionStatus::VALIDATED, $from, $to);

            $io->section(sprintf('Mail de validation (%d trouvés)', count($exemptions)));

            foreach ($exemptions as $exemption) {
                $io->writeln(sprintf(
                    '  [#%d] %s %s <%s>',
                    $exemption->getId(),
                    $exemption->getFirstName(),
                    $exemption->getLastName(),
                    $exemption->getEmail()
                ));

                if ($isDryRun) {
                    ++$totals['validated'];
                    continue;
                }

                try {
                    $this->validatedToEmployeeHandler->handle(new ExemptionValidatedToEmployee($exemption->getId()));
                    ++$totals['validated'];
                } catch (\Throwable $e) {
                    $io->error(sprintf('Erreur sur #%d: %s', $exemption->getId(), $e->getMessage()));
                    ++$totals['errors'];
                }
            }
        }

        // =====================================================================
        // REJECTED — exemptions rejected during the outage
        // =====================================================================
        if (in_array('rejected', $types, true)) {
            $exemptions = $explicitIds
                ? $this->findByStatusAndIds(ExemptionStatus::REJECTED, $explicitIds)
                : $this->findByStatusAndDateRange(ExemptionStatus::REJECTED, $from, $to);

            $io->section(sprintf('Mail de rejet (%d trouvés)', count($exemptions)));

            foreach ($exemptions as $exemption) {
                $io->writeln(sprintf(
                    '  [#%d] %s %s <%s>',
                    $exemption->getId(),
                    $exemption->getFirstName(),
                    $exemption->getLastName(),
                    $exemption->getEmail()
                ));

                if ($isDryRun) {
                    ++$totals['rejected'];
                    continue;
                }

                try {
                    $this->rejectedToEmployeeHandler->handle(new ExemptionRejectedToEmployee($exemption->getId()));
                    ++$totals['rejected'];
                } catch (\Throwable $e) {
                    $io->error(sprintf('Erreur sur #%d: %s', $exemption->getId(), $e->getMessage()));
                    ++$totals['errors'];
                }
            }
        }

        // =====================================================================
        // INIT — to_finalise exemptions created via initExemption endpoint.
        // These have no previousReason: the notification email is handled by
        // the calling system, not by us. Listed for information only.
        // =====================================================================
        if (in_array('init', $types, true)) {
            $exemptions = $explicitIds
                ? $this->findInitByIds($explicitIds)
                : $this->findInitByDateRange($from, $to);

            $io->section(sprintf('Pas de mail - nouvelles demandes de dispense (%d trouvés)', count($exemptions)));

            foreach ($exemptions as $exemption) {
                $io->writeln(sprintf(
                    '  [#%d] %s %s <%s> — aucun envoi de mail à effectuer de notre côté',
                    $exemption->getId(),
                    $exemption->getFirstName(),
                    $exemption->getLastName(),
                    $exemption->getEmail()
                ));
                ++$totals['init'];
            }
        }

        $io->success(sprintf(
            'Done%s — Renouvellement: %d | Validation: %d | Rejet: %d | Nouvelles (info): %d | Erreurs: %d',
            $isDryRun ? ' (exécution à blanc, aucun envoi)' : '',
            $totals['renewal'],
            $totals['validated'],
            $totals['rejected'],
            $totals['init'],
            $totals['errors']
        ));

        return $totals['errors'] > 0 ? 1 : 0;
    }

    /**
     * Renewals in a date range = to_finalise exemptions with a previousReason (created by withStatus()).
     *
     * @return Exemption[]
     */
    private function findRenewalByDateRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Exemption::class, 'e')
            ->where('e.status = :status')
            ->andWhere('e.previousReason IS NOT NULL')
            ->andWhere('e.isRenewCreated = 0')
            ->andWhere('(e.statusUpdatedAt BETWEEN :from AND :to) OR (e.createdAt BETWEEN :from AND :to)')
            ->setParameter('status', ExemptionStatus::TO_FINALISE)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Exemption[]
     */
    private function findRenewalByIds(array $ids): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Exemption::class, 'e')
            ->where('e.id IN (:ids)')
            ->andWhere('e.status = :status')
            ->andWhere('e.previousReason IS NOT NULL')
            ->andWhere('e.isRenewCreated = 0')
            ->setParameter('ids', $ids)
            ->setParameter('status', ExemptionStatus::TO_FINALISE)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Validated/rejected exemptions in a date range.
     *
     * @return Exemption[]
     */
    private function findByStatusAndDateRange(string $status, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Exemption::class, 'e')
            ->where('e.status = :status')
            ->andWhere('e.isRenewCreated = 0')
            ->andWhere('(e.statusUpdatedAt BETWEEN :from AND :to) OR (e.createdAt BETWEEN :from AND :to)')
            ->setParameter('status', $status)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return Exemption[]
     */
    private function findByStatusAndIds(string $status, array $ids): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Exemption::class, 'e')
            ->where('e.id IN (:ids)')
            ->andWhere('e.status = :status')
            ->andWhere('e.isRenewCreated = 0')
            ->setParameter('ids', $ids)
            ->setParameter('status', $status)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Init exemptions by explicit IDs = to_finalise without previousReason (created via initExemption endpoint).
     *
     * @param int[] $ids
     *
     * @return Exemption[]
     */
    private function findInitByIds(array $ids): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Exemption::class, 'e')
            ->where('e.id IN (:ids)')
            ->andWhere('e.status = :status')
            ->andWhere('e.previousReason IS NULL')
            ->setParameter('ids', $ids)
            ->setParameter('status', ExemptionStatus::TO_FINALISE)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Init exemptions in a date range = to_finalise without previousReason (created via initExemption endpoint).
     *
     * @return Exemption[]
     */
    private function findInitByDateRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Exemption::class, 'e')
            ->where('e.status = :status')
            ->andWhere('e.previousReason IS NULL')
            ->andWhere('(e.statusUpdatedAt BETWEEN :from AND :to) OR (e.createdAt BETWEEN :from AND :to)')
            ->setParameter('status', ExemptionStatus::TO_FINALISE)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult()
        ;
    }
}
