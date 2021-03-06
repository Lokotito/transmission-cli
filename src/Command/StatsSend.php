<?php

namespace Popstas\Transmission\Console\Command;

use InfluxDB;
use Martial\Transmission\API\Argument\Torrent;
use Popstas\Transmission\Console\Helpers\TorrentListUtils;
use Popstas\Transmission\Console\Helpers\TorrentUtils;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StatsSend extends Command
{
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('stats-send')
            ->setAliases(['ss'])
            ->setDescription('Send metrics to InfluxDB')
            ->addOption('transmission-host', null, InputOption::VALUE_OPTIONAL, 'Transmission host')
            ->setHelp(<<<EOT
## Send statistic to InfluxDB

Command should called every hour or more, possible every day, but it will less precise.

Command assume that all torrents have unique names.

You should to configure InfluxDB to use this functional.
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->getApplication()->getConfig();
        $logger = $this->getApplication()->getLogger();
        $client = $this->getApplication()->getClient();

        $torrentList = $client->getTorrentData();
        if (!$config->get('allow-duplicates')) {
            $obsoleteList = TorrentListUtils::getObsoleteTorrents($torrentList);
            if (!empty($obsoleteList)) {
                $output->writeln('<comment>Found obsolete torrents, '
                                 . 'remove it using transmission-cli torrent-remove-duplicates</comment>');
                TorrentListUtils::printTorrentsTable($obsoleteList, $output);
                return 1;
            }
        }

        try {
            $influxDbClient = $this->getApplication()->getInfluxDbClient(
                $config->get('influxdb-host'),
                $config->get('influxdb-port'),
                $config->get('influxdb-user'),
                $config->get('influxdb-password'),
                $config->get('influxdb-database')
            );

            $points = [];

            $transmissionHost = $config->get('transmission-host');

            foreach ($torrentList as $torrent) {
                $age = TorrentUtils::getTorrentAge($torrent);
                $torrentPoint = $influxDbClient->buildPoint($torrent, $transmissionHost);

                if ($age) {
                    $points[] = $torrentPoint;
                } else {
                    $logger->debug('Skip point: {point}', ['point' => $torrentPoint]);
                }
            }

            $points[] = $influxDbClient->buildStatus($torrentList, $transmissionHost);

            $this->dryRun($input, $output, function () use ($influxDbClient, $points) {
                $influxDbClient->writePoints($points);
            }, 'dry-run, don\'t really send points');
        } catch (\Exception $e) {
            $logger->critical($e->getMessage());
            return 1;
        }

        return 0;
    }
}
