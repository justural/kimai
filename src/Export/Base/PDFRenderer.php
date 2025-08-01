<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Export\Base;

use App\Entity\ExportableItem;
use App\Export\ExportFilename;
use App\Export\ExportRendererInterface;
use App\Export\TimesheetExportInterface;
use App\Pdf\HtmlToPdfConverter;
use App\Pdf\PdfContext;
use App\Pdf\PdfRendererTrait;
use App\Project\ProjectStatisticService;
use App\Repository\Query\TimesheetQuery;
use App\Twig\SecurityPolicy\ExportPolicy;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Extension\SandboxExtension;

class PDFRenderer implements DispositionInlineInterface, ExportRendererInterface, TimesheetExportInterface
{
    use RendererTrait;
    use PDFRendererTrait;

    private string $id = 'pdf';
    private string $title = 'pdf';
    private string $template = 'default.pdf.twig';
    private array $pdfOptions = [];

    public function __construct(
        private readonly Environment $twig,
        private readonly HtmlToPdfConverter $converter,
        private readonly ProjectStatisticService $projectStatisticService
    )
    {
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    protected function getTemplate(): string
    {
        return '@export/' . $this->template;
    }

    protected function getOptions(TimesheetQuery $query): array
    {
        $decimal = false;
        if (null !== $query->getCurrentUser()) {
            $decimal = $query->getCurrentUser()->isExportDecimal();
        } elseif (null !== $query->getUser()) {
            $decimal = $query->getUser()->isExportDecimal();
        }

        return ['decimal' => $decimal];
    }

    public function getPdfOptions(): array
    {
        return $this->pdfOptions;
    }

    public function setPdfOption(string $key, string $value): PDFRenderer
    {
        $this->pdfOptions[$key] = $value;

        return $this;
    }

    /**
     * @param ExportableItem[] $exportItems
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function render(array $exportItems, TimesheetQuery $query): Response
    {
        $filename = new ExportFilename($query);
        $context = new PdfContext();
        $context->setOption('filename', $filename->getFilename());

        $summary = $this->calculateSummary($exportItems);

        // enable basic security measures
        $sandbox = new SandboxExtension(new ExportPolicy());
        $sandbox->enableSandbox();
        $this->twig->addExtension($sandbox);

        $content = $this->twig->render($this->getTemplate(), array_merge([
            'entries' => $exportItems,
            'query' => $query,
            'summaries' => $summary,
            'budgets' => $this->calculateProjectBudget($exportItems, $query, $this->projectStatisticService),
            'decimal' => false,
            'pdfContext' => $context
        ], $this->getOptions($query)));

        $pdfOptions = array_merge($context->getOptions(), $this->getPdfOptions());

        $content = $this->converter->convertToPdf($content, $pdfOptions);

        return $this->createPdfResponse($content, $context);
    }

    public function setTemplate(string $filename): void
    {
        $this->template = $filename;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
