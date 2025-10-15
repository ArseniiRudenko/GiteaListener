<?php

namespace Leantime\Plugins\GiteaListener\Controllers;

use Leantime\Core\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Leantime\Plugins\GiteaListener\Repositories\GiteaListenerRepository;
use Leantime\Core\Controller\Frontcontroller;

class Settings extends Controller
{
    private GiteaListenerRepository $repository;

    /**
     * init
     *
     * @return void
     */
    public function init(GiteaListenerRepository $repository): void
    {
        $this->repository = $repository;
    }

    /**
     * get
     *
     * @return Response
     *
     * @throws \Exception
     */
    public function get(): Response
    {
        // Load existing configurations and pass to template
        $configs = [];
        try {
            $configs = $this->repository->getAll();
        } catch (\Throwable $e) {
            $this->tpl->setNotification('Could not load Gitea Listener configurations: '.$e->getMessage(), 'error');
        }

        $this->tpl->assign('giteaConfigs', $configs);

        return $this->tpl->display("GiteaListener.settings");
    }

    /**
     * post
     *
     * @param array $params
     * @return Response
     */
    public function post(array $params): Response
    {
        $req = $this->incomingRequest->request;

        $repositoryUrl = trim((string)$req->get('repository_url', ''));
        $accessToken = trim((string)$req->get('repository_access_token', ''));
        $branchFilter = trim((string)$req->get('branch_filter', '*')) ?: '*';

        // Basic validation
        if ($repositoryUrl === '' || filter_var($repositoryUrl, FILTER_VALIDATE_URL) === false) {
            $this->tpl->setNotification('Repository URL is required and must be a valid URL.', 'error');
            return Frontcontroller::redirect(BASE_URL.'/plugins/gitealistener/settings');
        }

        if ($accessToken === '') {
            $this->tpl->setNotification('Repository access token is required.', 'error');
            return Frontcontroller::redirect(BASE_URL.'/plugins/gitealistener/settings');
        }

        // Implementation details: plugin manages hook_id/hook_secret. Keep them internal.
        try {
            $hookSecret = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            $hookSecret = uniqid('gitea_', true);
        }

        // Prepare data and save
        $data = [
            'repository_url' => $repositoryUrl,
            'repository_access_token' => $accessToken,
            'hook_id' => 0,
            'hook_secret' => $hookSecret,
            'branch_filter' => $branchFilter,
        ];

        try {
            $savedId = $this->repository->save($data);
            if ($savedId === null) {
                $this->tpl->setNotification('Failed to save configuration.', 'error');
            } else {
                $this->tpl->setNotification('Gitea listener configuration saved.', 'success', 'gitea_config_saved');
            }
        } catch (\Throwable $e) {
            $this->tpl->setNotification('Error saving configuration: '.$e->getMessage(), 'error');
        }

        return Frontcontroller::redirect(BASE_URL.'/plugins/gitealistener/settings');
    }

    /**
     * update - update branch filter for a configuration (POST: id, branch_filter)
     * Returns JSON {success, message}
     */
    public function update(array $params): Response
    {
        $req = $this->incomingRequest->request;
        $id = $req->get('id', null);
        $branchFilter = trim((string)$req->get('branch_filter', ''));

        if ($id === null || !is_numeric($id)) {
            return new Response(json_encode(['success' => false, 'message' => 'Invalid id']), 400, ['Content-Type' => 'application/json']);
        }

        if ($branchFilter === '') {
            return new Response(json_encode(['success' => false, 'message' => 'Branch filter cannot be empty']), 400, ['Content-Type' => 'application/json']);
        }

        try {
            $ok = $this->repository->updateBranchFilter((int)$id, $branchFilter);
            if ($ok) {
                return new Response(json_encode(['success' => true, 'message' => 'Branch filter updated']), 200, ['Content-Type' => 'application/json']);
            }

            return new Response(json_encode(['success' => false, 'message' => 'Update failed']), 500, ['Content-Type' => 'application/json']);
        } catch (\Throwable $e) {
            return new Response(json_encode(['success' => false, 'message' => 'Error: '.$e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * delete - remove a configuration by id (POST: id)
     * Returns JSON {success, message}
     */
    public function delete(array $params): Response
    {
        $req = $this->incomingRequest->request;
        $id = $req->get('id', null);

        if ($id === null || !is_numeric($id)) {
            return new Response(json_encode(['success' => false, 'message' => 'Invalid id']), 400, ['Content-Type' => 'application/json']);
        }

        try {
            $ok = $this->repository->deleteById((int)$id);
            if ($ok) {
                return new Response(json_encode(['success' => true, 'message' => 'Configuration deleted']), 200, ['Content-Type' => 'application/json']);
            }

            return new Response(json_encode(['success' => false, 'message' => 'Delete failed']), 500, ['Content-Type' => 'application/json']);
        } catch (\Throwable $e) {
            return new Response(json_encode(['success' => false, 'message' => 'Error: '.$e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * test - accepts repository_url and repository_access_token, performs backend request to Gitea API
     * and returns JSON with success/message. This avoids testing from the frontend directly.
     *
     * @param array $params
     * @return Response
     */
    public function test(array $params): Response
    {
        $req = $this->incomingRequest->request;

        $repositoryUrl = trim((string)$req->get('repository_url', ''));
        $accessToken = trim((string)$req->get('repository_access_token', ''));

        if ($repositoryUrl === '' || filter_var($repositoryUrl, FILTER_VALIDATE_URL) === false) {
            return new Response(json_encode(['success' => false, 'message' => 'Invalid repository URL']), 400, ['Content-Type' => 'application/json']);
        }

        if ($accessToken === '') {
            return new Response(json_encode(['success' => false, 'message' => 'Access token is required']), 400, ['Content-Type' => 'application/json']);
        }

        // Build base host and call /api/v1/user to validate token
        $parsed = parse_url(rtrim($repositoryUrl, '/'));
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return new Response(json_encode(['success' => false, 'message' => 'Could not parse repository URL']), 400, ['Content-Type' => 'application/json']);
        }

        $base = $parsed['scheme'].'://'.$parsed['host'];
        if (isset($parsed['port'])) {
            $base .= ':'.$parsed['port'];
        }

        $userApi = $base.'/api/v1/user';

        $headers = [
            'Accept: application/json',
            'Authorization: token '.$accessToken,
            'User-Agent: Leantime-GiteaListener/1.0',
        ];

        $result = ['success' => false, 'message' => 'Connection failed'];

        if (function_exists('curl_version')) {
            $ch = curl_init($userApi);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            // In environments without proper CA bundles this may fail; set to true for security.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($resp === false) {
                $result = ['success' => false, 'message' => 'Connection failed: '.$curlErr];
            } else {
                if ($httpCode >= 200 && $httpCode < 300) {
                    $result = ['success' => true, 'message' => 'Connection successful'];
                } else {
                    $body = @json_decode($resp, true);
                    $msg = 'API returned HTTP '.$httpCode;
                    if (is_array($body) && isset($body['message'])) {
                        $msg .= ': '.$body['message'];
                    }
                    $result = ['success' => false, 'message' => $msg];
                }
            }
        } else {
            // fallback
            $opts = [
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers),
                    'timeout' => 8,
                    'ignore_errors' => true,
                ],
            ];
            $context = stream_context_create($opts);
            $resp = @file_get_contents($userApi, false, $context);

            $httpCode = 0;
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $hdr) {
                    if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $hdr, $m)) {
                        $httpCode = (int)$m[1];
                        break;
                    }
                }
            }

            if ($resp === false) {
                $result = ['success' => false, 'message' => 'Connection failed'];
            } else {
                if ($httpCode >= 200 && $httpCode < 300) {
                    $result = ['success' => true, 'message' => 'Connection successful'];
                } else {
                    $body = @json_decode($resp, true);
                    $msg = 'API returned HTTP '.$httpCode;
                    if (is_array($body) && isset($body['message'])) {
                        $msg .= ': '.$body['message'];
                    }
                    $result = ['success' => false, 'message' => $msg];
                }
            }
        }

        return new Response(json_encode($result), ($result['success'] ? 200 : 400), ['Content-Type' => 'application/json']);
    }
}
