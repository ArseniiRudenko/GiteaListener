<?php

namespace Leantime\Plugins\GiteaListener\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Plugins\GiteaListener\Repositories\GiteaListenerRepository;
use Leantime\Plugins\GiteaListener\Repositories\TicketHistoryRepository;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class Hook extends Controller
{
    private GiteaListenerRepository $repository;

    private TicketHistoryRepository $ticketHistoryRepository;

    public function init(GiteaListenerRepository $repository, TicketHistoryRepository $ticketHistoryRepository): void
    {
        $this->repository = $repository;
        $this->ticketHistoryRepository = $ticketHistoryRepository;
    }

    /**
     * Receive webhook POST from Gitea
     *
     * @param array $params
     * @return Response
     */
    public function post(array $params): Response
    {
        // Raw payload
        $raw = $this->incomingRequest->getContent();



        if (empty($raw)) {
            Log::warning('GiteaListener Hook: empty payload');
            return new Response('Empty payload', 400);
        }

        // signature headers
        $sigHeaders = [
            'X-Gitea-Signature',
            'X-Hub-Signature',
            'X-Hub-Signature-256',
        ];
        $signature = null;
        foreach ($sigHeaders as $h) {
            $v = $this->incomingRequest->headers->get($h);
            if (!empty($v)) {
                $signature = $v;
                //remove sha256= from the beginning of signature if present
                if (str_starts_with($signature, 'sha256=')) {
                    $signature = substr($signature, 7);
                }

                break;
            }
        }

        // decode JSON
        $payload = @json_decode($raw, true);
        if (!is_array($payload)) {
            Log::warning('GiteaListener Hook: invalid JSON');
            return new Response('Invalid JSON', 400);
        }

        Log::info("Incoming Gitea webhook payload: ".$raw);

        // Try to find matching configuration
        $configs = $this->repository->getAll();
        $matched = null;

        // If signature present, use it to match
        if ($signature !== null) {
            foreach ($configs as $cfg) {
                $secret = $cfg['hook_secret'] ?? '';
                if ($secret === '') continue;
                // compute expected signature
                $expected = hash_hmac('sha256', $raw, $secret);
                if (hash_equals($expected, $signature) || hash_equals($secret, $signature)) {
                    $matched = $cfg;
                    break;
                }
            }
        }

        // If not matched by signature, attempt to match by repository url in payload
        if ($matched === null && isset($payload['repository'])) {
            $repoInfo = $payload['repository'];
            $repoUrls = [];
            foreach (['html_url', 'url', 'clone_url', 'git_http_url', 'ssh_url'] as $k) {
                if (!empty($repoInfo[$k])) {
                    $repoUrls[] = (string) $repoInfo[$k];
                }
            }

            foreach ($configs as $cfg) {
                foreach ($repoUrls as $u) {
                    if ($u === '') {
                        continue;
                    }
                    // compare normalized urls (strip .git)
                    $a = preg_replace('/\.git$/', '', rtrim($u, '/'));
                    $b = preg_replace('/\.git$/', '', rtrim($cfg['repository_url'] ?? '', '/'));
                    if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
                        $matched = $cfg;
                        break 2;
                    }
                }
            }
        }

        if ($matched === null) {
            Log::warning('GiteaListener Hook: no matching config for incoming webhook');
            return new Response(json_encode(['success' => false, 'message' => 'No matching repository configuration found']), 404, ['Content-Type' => 'application/json']);
        }

        // Verify signature if secret exists
        $secret = $matched['hook_secret'] ?? '';
        if ($signature !== null && $secret !== '') {
            $expected = hash_hmac('sha256', $raw, $secret);
            if (!hash_equals($expected, $signature) && !hash_equals($secret, $signature)) {
                Log::warning('GiteaListener Hook: signature mismatch');
                return new Response(json_encode(['success' => false, 'message' => 'Signature mismatch']), 403, ['Content-Type' => 'application/json']);
            }
        }

        $pusherName = $payload['pusher']['full_name'] ?? '';
        $pusherLogin = $payload['pusher']['login'] ?? '';
        $pusherEmail = $payload['pusher']['email'] ?? '';


        $commitsArray = $payload['commits'] ?? [];
        foreach ($commitsArray as $commit) {
            $commitMessage = $commit['message'] ?? '';
            $branch = preg_replace('/^refs\/heads\//', '', $payload['ref']);
            $authorName = $commit['author']['name'] ?? '';
            $authorEmail = $commit['author']['email'] ?? '';
            $authorUsername = $commit['author']['username'] ?? '';
            $commitSha = $commit['id'] ?? '';

            $userId = 0; // default to system/unknown user
            try {
                $uid = null;

                // Try by email (best unique identifier)
                if ($uid === null && $authorEmail !== '') {
                    $uid = $this->ticketHistoryRepository->GetUserIdByEmail($authorEmail);
                }

                if ($uid === null && $pusherEmail !== '') {
                    $uid = $this->ticketHistoryRepository->GetUserIdByEmail($pusherEmail);
                }

                // Try by username first
                if ($authorUsername !== '') {
                    $uid = $this->ticketHistoryRepository->GetUserIdByEmail($authorUsername);
                }

                // Then by full name exact
                if ($uid === null && $authorName !== '') {
                    $uid = $this->ticketHistoryRepository->GetUserIdByFullName($authorName);
                }

                if ($uid === null && $pusherName !== '') {
                    $uid = $this->ticketHistoryRepository->GetUserIdByFullName($pusherName);
                }

                if ($uid === null && $pusherLogin !== '') {
                    $uid = $this->ticketHistoryRepository->GetUserIdByFullName($pusherLogin);
                }

                // Finally, best-effort partial name (prioritize surname)
                if ($uid === null && $authorName !== '') {
                    $uid = $this->ticketHistoryRepository->GetUserIdByPartialName($authorName);
                }

                if ($uid === null && $authorUsername !== '') {
                    $uid = $this->ticketHistoryRepository->GetUserIdByPartialName($authorUsername);
                }

                if ($uid === null && $pusherName !== '') {
                    $uid = $this->ticketHistoryRepository->GetUserIdByPartialName($pusherName);
                }

                if ($uid === null && $pusherLogin !== '') {
                    $uid = $this->ticketHistoryRepository->GetUserIdByPartialName($pusherLogin);
                }

                if (is_int($uid)) {
                    $userId = $uid;
                }
            } catch (\Throwable $e) {
                Log::warning('GiteaListener Hook: could not resolve author to user id: ' . $e->getMessage());
            }

            //parse branch and commit message for ticket references (#123), check tickets and insert into zp_tickethistory
            try {
                $matches = [];

                // find all #number in commit message
                if (!empty($commitMessage)) {
                    if (preg_match_all('/#(\d+)/', $commitMessage, $m)) {
                        foreach ($m[1] as $num) {
                            $matches[] = (int)$num;
                        }
                    }
                }

                // find all #number in branch
                if (!empty($branch)) {
                    if (preg_match_all('/#(\d+)/', $branch, $m2)) {
                        foreach ($m2[1] as $num) {
                            $matches[] = (int)$num;
                        }
                    }
                }
                $commitLink = $commit['url'] ?? '';
                // dedupe and sanitize
                $ticketIds = array_values(array_unique(array_filter(array_map('intval', $matches))));

                Log::info('GiteaListener Hook: found ' . count($ticketIds) . ' ticket references in commit ' . $commitSha);

                if (!empty($ticketIds)) {
                    foreach ($ticketIds as $ticketId) {
                        try {
                            // Check if ticket exists
                            $found = $this->ticketHistoryRepository->TicketExists($ticketId);
                            if ($found) {
                                // Insert into zp_tickethistory
                                $result = $this->ticketHistoryRepository->AddHistory($ticketId, $userId, 'commit', $commitLink."||".$commitMessage);

                                if (!$result) {
                                    Log::error('GiteaListener Hook: failed to record history for ticket #' . $ticketId);
                                    continue;
                                }
                                Log::info('GiteaListener Hook: recorded commit for ticket #' . $ticketId . ' commit ' . $commitSha);
                            }
                        } catch (\Throwable $e) {
                            Log::error('GiteaListener Hook: error writing history for ticket #' . $ticketId . ': ' . $e->getMessage());
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error('GiteaListener Hook: error while processing ticket linking: ' . $e->getMessage());
            }
        }

        return new Response(null, 200, ['Content-Type' => 'application/json']);
    }
}

