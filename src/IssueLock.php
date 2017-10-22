<?php
declare(strict_types=1);
namespace NamelessCoder\GithubIssueLock;

class IssueLock
{
    public function lock(string $repository, string $token, int $days, ?string $message = null)
    {
        $since = $this->getLastRunTime($repository)->format('c');
        $baseUrl = str_replace('https://github.com/', 'https://api.github.com/repos/', $repository);
        $issuesUrl = $baseUrl . '/issues?state=closed&since=' . $since;

        $page = 1;
        $closingTime = new \DateTime('-' . $days . ' days');
        $closingTimestamp = $closingTime->format('U');
        while (($closedIssues = $this->makeRequest('GET', $issuesUrl . '&page=' . $page, $token)) && !empty($closedIssues) && $page++) {
            foreach ($closedIssues as $closedIssue) {
                if ($closedIssue['locked']) {
                    echo $closedIssue['html_url'] . ' is already locked, skipping.' . PHP_EOL;
                    continue;
                }
                $closedSince = new \DateTime($closedIssue['closed_at']);
                $closedSinceTimestamp = $closedSince->format('U');

                if ($closedSince < $closingTimestamp) {
                    echo $closedIssue['html_url'] . ' is ' . ceil(($closedSinceTimestamp - $closingTimestamp) / 86400) . ' day(s) above locking limit - locking issue...' . PHP_EOL;
                    $this->makeRequest('POST', $closedIssue['comments_url'], $token, [
                        'body' => $message ?: 'Auto-locking issue'
                    ]);
                    $this->makeRequest('PUT', $closedIssue['url'] . '/lock', $token);
                } else {
                    echo $closedIssue['html_url'] . ' is ' . abs(ceil(($closingTimestamp - $closedSinceTimestamp) / 86400)) . ' day(s) away from locking.' . PHP_EOL;
                }
            }
        }
        $this->saveRunTime($repository);
    }

    protected function getLastRunTime(string $repository): \DateTime
    {
        $stampFilename = 'stamps/stamp-' . sha1($repository);
        if (file_exists($stampFilename)) {
            return new \DateTime(file_get_contents($stampFilename));
        }
        return new \DateTime(date('c', 1));
    }

    protected function saveRunTime(string $repository)
    {
        file_put_contents('stamps/stamp-' . sha1($repository), date('c'));
    }

    protected function makeRequest(string $method, string $url, string $token, array $data = []): array
    {
        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'Authorization: token ' . $token
        ];
        if (count($data)) {
            $json = json_encode($data);
            $headers[] = 'Content-Length: ' . strlen($json);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_USERAGENT, 'NamelessCoder/GithubIssueLock 0.1');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        return json_decode($response, true) ?? [];
    }
}
