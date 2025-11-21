<?php

namespace App\Command;

use Survos\SaisBundle\Service\SaisClientService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'http:get',
    description: 'fetch a url',
)]
class HttpGetCommand extends Command
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private SaisClientService $saisService,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::OPTIONAL, 'Url to fetch')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = $input->getArgument('url');
        $response = $this->httpClient->request('GET', $url, [
//            'proxy' => '127.0.0.1:7080',
        ]);
        $io->write("Fetching url: {$url}");
        if ($response->getStatusCode() !== 200) {
            $io->error($response->getStatusCode());
        }
        $io->writeln($response->getContent());

        $io->writeln("Fetching url: {$url} via service");
        $response = $this->saisService->fetch('/status');
        $io->write(json_encode($response, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
