<?php

namespace Leantime\Plugins\GiteaListener\Services;

use Illuminate\Support\Facades\Log;
use Leantime\Plugins\GiteaListener\Repositories\GiteaListenerRepository;

class GiteaListener {

    private GiteaListenerRepository $repository;

    public function __construct()
    {
        $this->repository = new GiteaListenerRepository();
    }

    public function install(): void
    {
        // Repo call to create tables.
        $this->repository->setup();
        Log::info('Gitea Listener plugin Installed');
    }

    public function uninstall(): void
    {
        // Remove tables
        $this->repository->teardown();
        Log::info('Gitea Listener plugin Uninstalled');
    }
}
