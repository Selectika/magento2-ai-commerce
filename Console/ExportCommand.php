<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Console;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use Selectika\AiCommerce\Model\ExportCatalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ExportCommand extends Command
{
    public function __construct(private ExportCatalog $exporter, private StoreManagerInterface $stores, private State $state)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('selectika:catalog:export')->setDescription('Publish a complete Selectika catalog snapshot for one store view')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view code');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = (string) $input->getOption('store');
        if ($code === '' || $code === 'admin') {
            $output->writeln('<error>Pass --store with a storefront store view code.</error>');
            return 1;
        }
        try {
            $manifest = $this->state->emulateAreaCode(Area::AREA_CRONTAB, function () use ($code): array {
                return $this->exporter->export((int) $this->stores->getStore($code)->getId());
            });
            $output->writeln(sprintf('Published snapshot %s: %d rows in %d pages.', $manifest['snapshot_id'], $manifest['total_rows'], count($manifest['pages'])));
            return 0;
        } catch (Throwable $error) {
            $output->writeln('<error>Catalog export failed. Check Magento logs and configuration. The prior snapshot was retained.</error>');
            return 1;
        }
    }
}
