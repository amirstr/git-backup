<?php

namespace App\Commands\Gist;

use App\Services\JsonDecoder;
use App\Traits\HasForcedOptions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Command\Command as Output;

class GetCommand extends Command
{
    use HasForcedOptions;

    private string $toDir;
    private ?string $token = null;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'gist:get
        {--config=}
        {--to-dir=}
    ';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Gets all gists.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (! $this->hasAllOptions('config', 'to-dir')) {
            return Output::FAILURE;
        }

        if (! is_dir($this->option('to-dir'))) {
            $this->error("Directory not found: ".$this->option('to-dir'));
            return Output::FAILURE;
        }

        if (! is_file($this->option('config'))) {
            $this->error("Config file not found: ".$this->option('config'));
            return Output::FAILURE;
        }

        $decodedJson = JsonDecoder::decodePath($this->option('config'));
        $username = $decodedJson['username'];
        $token = isset($decodedJson['token']) ? $decodedJson['token'] : null;
        $proceededGists = 0;

        $this->token = $token;
        $this->toDir = str($this->option('to-dir'))->rtrim(DIRECTORY_SEPARATOR);
        $this->info('Getting gists...');

        for ($i=1; $i <= 1000; $i++) {
            $response = $this->getGists($username, $token, page: $i);

            if ($response === []) {
                break;
            }

            foreach($response as $gistJson) {
                try {
                    $this->backupGist($gistJson);
                } catch (\Exception $e) {
                    $this->error($e);
                    return Output::FAILURE;
                }
                $proceededGists ++;
            }
        }

        $this->newLine();
        $this->info('Total proceeded gists: '.$proceededGists);

        return Output::SUCCESS;
    }

    private function getGists(string $username, ?string $token, int $page): array
    {
        $http = Http::retry(3, 100)->acceptJson();

        if ($token) {
            $http->withToken($token);
        }

        $githubApiUrl = "https://api.github.com/users/{$username}/gists?per_page=50&page={$page}";

        return $http->get($githubApiUrl)->json();
    }

    private function backupGist(array $gistJson): void
    {
        $this->newLine();

        $id = $gistJson['id'];
        $username = isset($gistJson['owner']['login']) && filled($username = $gistJson['owner']['login'])
            ? $username
            : null;
        $description = isset($gistJson['description']) && filled($description = $gistJson['description'])
            ? $description
            : null;

        $allGistsDirName = $username ? "{$username}_gists" : 'gists';
        $gistDirName = $description ? str("{$description}__{$id}")->slug() : $id;

        $allGistsDirPath = str(pathable("{$this->toDir}/{$allGistsDirName}"))->rtrim(DIRECTORY_SEPARATOR);;
        $gistDirPath = str(pathable("{$allGistsDirPath}/{$gistDirName}"))->rtrim(DIRECTORY_SEPARATOR);;
        $gistIndicator = $description ?? $id;

        if (! is_dir($allGistsDirPath)) {
            mkdir($allGistsDirPath);
        }
        if (! is_dir($gistDirPath)) {
            mkdir($gistDirPath);
        }

        $this->info("Processing <comment>".$gistIndicator.'</comment>');

        foreach ($gistJson['files'] as $file) {
            $filePath = pathable("{$gistDirPath}/{$file['filename']}");

            $http = Http::retry(3, 100)->acceptJson();

            if ($this->token) {
                $http->withToken($this->token);
            }

            $gistContent = $http->get($file['raw_url'])->body();

            $this->processFile($file['filename'], $filePath, $gistContent);
        }

        $commentsTxtContent = '';

        for ($i=1; $i <= 1000 ; $i++) {
            $http = Http::retry(3, 100)->acceptJson();

            if ($this->token) {
                $http->withToken($this->token);
            }

            $responseComments = $http->get($gistJson['comments_url']."?page={$i}")->json();

            if (blank($responseComments)) {
                break;
            }

            foreach ($responseComments as $comment) {
                $author = $comment['user']['login'];
                $body = $comment['body'];
                $createdAt = $comment['created_at'];
                $updatedAt = $comment['updated_at'];

                $title = "created_at: [{$createdAt}] updated_at: [{$updatedAt}] author: {$author}";
                $titleSeparator = $this->createTitleSeparator(len: strlen($title));

                $commentsTxtContent .= $titleSeparator.PHP_EOL;
                $commentsTxtContent .= $title . PHP_EOL;
                $commentsTxtContent .= $titleSeparator.PHP_EOL;
                $commentsTxtContent .= $body.PHP_EOL;
            }
        }

        $commentsPath = pathable("{$gistDirPath}/comments.txt");
        $commentsTxtContent = str($commentsTxtContent)->trim(PHP_EOL)->toString();

        if (! blank($commentsTxtContent)) {
            $this->processFile('comments.txt', $commentsPath, $commentsTxtContent);
        }
    }

    private function createTitleSeparator(int $len = 0, $separator = '-'): string
    {
        $generatedSeparator = '';

        for ($i=1; $i <= $len ; $i++) {
            $generatedSeparator .= $separator;
        }

        return $generatedSeparator;
    }

    private function processFile(string $filename, string $filePath, string $putContents): void
    {
        if (! $fileExists = is_file($filePath)) {
            $this->info("Creating file <comment>$filename</comment>");
            file_put_contents($filePath, $putContents);
        }

        $update = $fileExists;

        if ($fileExists) {
            $update = $update && md5(file_get_contents($filePath)) !== md5($putContents);
        }

        if (! $update) {
            return;
        } else {
            $this->info("Updating file <comment>$filename</comment>");
        }

        if (blank($putContents)) {
            $putContents = ' ';
        }

        if (! file_put_contents($filePath, $putContents)) {
            throw new \Exception("Counldn't put content to file {$filePath}");
        }
    }
}
