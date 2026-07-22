<?php

namespace App\Controller;

use App\Entity\PlanTemplate;
use App\Entity\Workout;
use App\Repository\ScheduledWorkoutRepository;
use App\Security\Voter\PlanTemplateVoter;
use App\Security\Voter\WorkoutVoter;
use App\Service\ExcelExporter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Export Excel (Phase 8) : sort une séance, un plan ou un planning daté en .xlsx.
 *
 * Le contrôleur reste mince : toute la mise en forme vit dans {@see ExcelExporter},
 * qui consomme lui-même {@see \App\Service\PlanFlattener} (source unique de mise à
 * plat). Ici on ne fait qu'autoriser, déléguer et streamer.
 */
#[Route('/export')]
final class ExportController extends AbstractController
{
    /** Noms de mois en français (index 1..12), cohérent avec calendrier/synthèse. */
    private const MONTH_NAMES = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    public function __construct(private readonly ExcelExporter $exporter)
    {
    }

    #[Route('/workout/{id}', name: 'app_export_workout', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function workout(Workout $workout): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::VIEW, $workout);

        return $this->xlsxResponse(
            $this->exporter->workout($workout),
            $this->filename('seance', $workout->getSlug() ?? (string) $workout->getId()),
        );
    }

    #[Route('/plan-template/{id}', name: 'app_export_plan_template', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function planTemplate(PlanTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);

        return $this->xlsxResponse(
            $this->exporter->planTemplate($template),
            $this->filename('plan', $template->getSlug() ?? (string) $template->getId()),
        );
    }

    #[Route('/schedule/{year}/{month}', name: 'app_export_schedule', methods: ['GET'], requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'])]
    public function schedule(int $year, int $month, ScheduledWorkoutRepository $repository): Response
    {
        if ($month < 1 || $month > 12) {
            throw $this->createNotFoundException('Mois invalide.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $last = $first->modify('last day of this month');
        $periodLabel = self::MONTH_NAMES[$month].' '.$year;

        $scheduled = $repository->findByOwnerBetween($user, $first, $last);

        return $this->xlsxResponse(
            $this->exporter->schedule($scheduled, $periodLabel),
            $this->filename('planning', sprintf('%04d-%02d', $year, $month)),
        );
    }

    /**
     * Streame un classeur en réponse HTTP téléchargeable. Le writer écrit dans
     * `php://output` : la génération se fait pendant l'envoi, sans fichier temporaire.
     */
    private function xlsxResponse(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        });

        $response->headers->set(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        );
        // Réponse dynamique et authentifiée : jamais mise en cache par un proxy.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function filename(string $prefix, string $identifier): string
    {
        return sprintf('kadens-%s-%s.xlsx', $prefix, $identifier);
    }
}
