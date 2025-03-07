<?php

namespace App\Jobs;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Shared\ComplexStatusCheck;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Notifications\Container\ContainerRestarted;
use App\Notifications\Container\ContainerStopped;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class ContainerStatusJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 4;
    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }
    public function __construct(public Server $server)
    {
    }
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->server->uuid))];
    }

    public function uniqueId(): int
    {
        return $this->server->uuid;
    }

    public function handle()
    {
        if (!$this->server->isFunctional()) {
            return 'Server is not ready.';
        };

        $applications = $this->server->applications();
        $skip_these_applications = collect([]);
        foreach ($applications as $application) {
            if ($application->additional_servers->count() > 0) {
                $skip_these_applications->push($application);
                ComplexStatusCheck::run($application);
                $applications = $applications->filter(function ($value, $key) use ($application) {
                    return $value->id !== $application->id;
                });
            }
        }
        $applications = $applications->filter(function ($value, $key) use ($skip_these_applications) {
            return !$skip_these_applications->pluck('id')->contains($value->id);
        });
        try {
            if ($this->server->isSwarm()) {
                $containers = instant_remote_process(["docker service inspect $(docker service ls -q) --format '{{json .}}'"], $this->server, false);
                $containerReplicates = instant_remote_process(["docker service ls --format '{{json .}}'"], $this->server, false);
            } else {
                // Precheck for containers
                $containers = instant_remote_process(["docker container ls -q"], $this->server, false);
                if (!$containers) {
                    return;
                }
                $containers = instant_remote_process(["docker container inspect $(docker container ls -q) --format '{{json .}}'"], $this->server, false);
                $containerReplicates = null;
            }
            if (is_null($containers)) {
                return;
            }
            $containers = format_docker_command_output_to_json($containers);
            if ($containerReplicates) {
                $containerReplicates = format_docker_command_output_to_json($containerReplicates);
                foreach ($containerReplicates as $containerReplica) {
                    $name = data_get($containerReplica, 'Name');
                    $containers = $containers->map(function ($container) use ($name, $containerReplica) {
                        if (data_get($container, 'Spec.Name') === $name) {
                            $replicas = data_get($containerReplica, 'Replicas');
                            $running = str($replicas)->explode('/')[0];
                            $total = str($replicas)->explode('/')[1];
                            if ($running === $total) {
                                data_set($container, 'State.Status', 'running');
                                data_set($container, 'State.Health.Status', 'healthy');
                            } else {
                                data_set($container, 'State.Status', 'starting');
                                data_set($container, 'State.Health.Status', 'unhealthy');
                            }
                        }
                        return $container;
                    });
                }
            }
            $databases = $this->server->databases();
            $services = $this->server->services()->get();
            $previews = $this->server->previews();
            $foundApplications = [];
            $foundApplicationPreviews = [];
            $foundDatabases = [];
            $foundServices = [];

            foreach ($containers as $container) {
                if ($this->server->isSwarm()) {
                    $labels = data_get($container, 'Spec.Labels');
                    $uuid = data_get($labels, 'coolify.name');
                } else {
                    $labels = data_get($container, 'Config.Labels');
                }
                $containerStatus = data_get($container, 'State.Status');
                $containerHealth = data_get($container, 'State.Health.Status', 'unhealthy');
                $containerStatus = "$containerStatus ($containerHealth)";
                $labels = Arr::undot(format_docker_labels_to_json($labels));
                $applicationId = data_get($labels, 'coolify.applicationId');
                if ($applicationId) {
                    $pullRequestId = data_get($labels, 'coolify.pullRequestId');
                    if ($pullRequestId) {
                        if (str($applicationId)->contains('-')) {
                            $applicationId = str($applicationId)->before('-');
                        }
                        $preview = ApplicationPreview::where('application_id', $applicationId)->where('pull_request_id', $pullRequestId)->first();
                        if ($preview) {
                            $foundApplicationPreviews[] = $preview->id;
                            $statusFromDb = $preview->status;
                            if ($statusFromDb !== $containerStatus) {
                                $preview->update(['status' => $containerStatus]);
                            }
                        } else {
                            //Notify user that this container should not be there.
                        }
                    } else {
                        $application = $applications->where('id', $applicationId)->first();
                        if ($application) {
                            $foundApplications[] = $application->id;
                            $statusFromDb = $application->status;
                            if ($statusFromDb !== $containerStatus) {
                                $application->update(['status' => $containerStatus]);
                            }
                        } else {
                            //Notify user that this container should not be there.
                        }
                    }
                } else {
                    $uuid = data_get($labels, 'com.docker.compose.service');
                    if ($uuid) {
                        $database = $databases->where('uuid', $uuid)->first();
                        if ($database) {
                            $isPublic = data_get($database, 'is_public');
                            $foundDatabases[] = $database->id;
                            $statusFromDb = $database->status;
                            if ($statusFromDb !== $containerStatus) {
                                $database->update(['status' => $containerStatus]);
                            }
                            if ($isPublic) {
                                $foundTcpProxy = $containers->filter(function ($value, $key) use ($uuid) {
                                    if ($this->server->isSwarm()) {
                                        return data_get($value, 'Spec.Name') === "coolify-proxy_$uuid";
                                    } else {
                                        return data_get($value, 'Name') === "/$uuid-proxy";
                                    }
                                })->first();
                                if (!$foundTcpProxy) {
                                    StartDatabaseProxy::run($database);
                                    $this->server->team?->notify(new ContainerRestarted("TCP Proxy for {$database->name}", $this->server));
                                }
                            }
                        } else {
                            // Notify user that this container should not be there.
                        }
                    }
                    if (data_get($container, 'Name') === '/coolify-db') {
                        $foundDatabases[] = 0;
                    }
                }
                $serviceLabelId = data_get($labels, 'coolify.serviceId');
                if ($serviceLabelId) {
                    $subType = data_get($labels, 'coolify.service.subType');
                    $subId = data_get($labels, 'coolify.service.subId');
                    $service = $services->where('id', $serviceLabelId)->first();
                    if (!$service) {
                        continue;
                    }
                    if ($subType === 'application') {
                        $service =  $service->applications()->where('id', $subId)->first();
                    } else {
                        $service =  $service->databases()->where('id', $subId)->first();
                    }
                    if ($service) {
                        $foundServices[] = "$service->id-$service->name";
                        $statusFromDb = $service->status;
                        if ($statusFromDb !== $containerStatus) {
                            // ray('Updating status: ' . $containerStatus);
                            $service->update(['status' => $containerStatus]);
                        }
                    }
                }
            }
            $exitedServices = collect([]);
            foreach ($services as $service) {
                $apps = $service->applications()->get();
                $dbs = $service->databases()->get();
                foreach ($apps as $app) {
                    if (in_array("$app->id-$app->name", $foundServices)) {
                        continue;
                    } else {
                        $exitedServices->push($app);
                    }
                }
                foreach ($dbs as $db) {
                    if (in_array("$db->id-$db->name", $foundServices)) {
                        continue;
                    } else {
                        $exitedServices->push($db);
                    }
                }
            }
            $exitedServices = $exitedServices->unique('id');
            foreach ($exitedServices as $exitedService) {
                if (str($exitedService->status)->startsWith('exited')) {
                    continue;
                }
                $name = data_get($exitedService, 'name');
                $fqdn = data_get($exitedService, 'fqdn');
                $containerName = $name ? "$name, available at $fqdn" : $fqdn;
                $projectUuid = data_get($service, 'environment.project.uuid');
                $serviceUuid = data_get($service, 'uuid');
                $environmentName = data_get($service, 'environment.name');

                if ($projectUuid && $serviceUuid && $environmentName) {
                    $url =  base_url() . '/project/' . $projectUuid . "/" . $environmentName . "/service/" . $serviceUuid;
                } else {
                    $url = null;
                }
                $this->server->team?->notify(new ContainerStopped($containerName, $this->server, $url));
                $exitedService->update(['status' => 'exited']);
            }

            $notRunningApplications = $applications->pluck('id')->diff($foundApplications);
            foreach ($notRunningApplications as $applicationId) {
                $application = $applications->where('id', $applicationId)->first();
                if (str($application->status)->startsWith('exited')) {
                    continue;
                }
                $application->update(['status' => 'exited']);

                $name = data_get($application, 'name');
                $fqdn = data_get($application, 'fqdn');

                $containerName = $name ? "$name ($fqdn)" : $fqdn;

                $projectUuid = data_get($application, 'environment.project.uuid');
                $applicationUuid = data_get($application, 'uuid');
                $environment = data_get($application, 'environment.name');

                if ($projectUuid && $applicationUuid && $environment) {
                    $url =  base_url() . '/project/' . $projectUuid . "/" . $environment . "/application/" . $applicationUuid;
                } else {
                    $url = null;
                }

                $this->server->team?->notify(new ContainerStopped($containerName, $this->server, $url));
            }
            $notRunningApplicationPreviews = $previews->pluck('id')->diff($foundApplicationPreviews);
            foreach ($notRunningApplicationPreviews as $previewId) {
                $preview = $previews->where('id', $previewId)->first();
                if (str($preview->status)->startsWith('exited')) {
                    continue;
                }
                $preview->update(['status' => 'exited']);

                $name = data_get($preview, 'name');
                $fqdn = data_get($preview, 'fqdn');

                $containerName = $name ? "$name ($fqdn)" : $fqdn;

                $projectUuid = data_get($preview, 'application.environment.project.uuid');
                $environmentName = data_get($preview, 'application.environment.name');
                $applicationUuid = data_get($preview, 'application.uuid');

                if ($projectUuid && $applicationUuid && $environmentName) {
                    $url =  base_url() . '/project/' . $projectUuid . "/" . $environmentName . "/application/" . $applicationUuid;
                } else {
                    $url = null;
                }

                $this->server->team?->notify(new ContainerStopped($containerName, $this->server, $url));
            }
            $notRunningDatabases = $databases->pluck('id')->diff($foundDatabases);
            foreach ($notRunningDatabases as $database) {
                $database = $databases->where('id', $database)->first();
                if (str($database->status)->startsWith('exited')) {
                    continue;
                }
                $database->update(['status' => 'exited']);

                $name = data_get($database, 'name');
                $fqdn = data_get($database, 'fqdn');

                $containerName = $name;

                $projectUuid = data_get($database, 'environment.project.uuid');
                $environmentName = data_get($database, 'environment.name');
                $databaseUuid = data_get($database, 'uuid');

                if ($projectUuid && $databaseUuid && $environmentName) {
                    $url = base_url() . '/project/' . $projectUuid . "/" . $environmentName . "/database/" . $databaseUuid;
                } else {
                    $url = null;
                }
                $this->server->team?->notify(new ContainerStopped($containerName, $this->server, $url));
            }

            // Check if proxy is running
            $this->server->proxyType();
            $foundProxyContainer = $containers->filter(function ($value, $key) {
                if ($this->server->isSwarm()) {
                    return data_get($value, 'Spec.Name') === 'coolify-proxy_traefik';
                } else {
                    return data_get($value, 'Name') === '/coolify-proxy';
                }
            })->first();
            if (!$foundProxyContainer) {
                try {
                    $shouldStart = CheckProxy::run($this->server);
                    if ($shouldStart) {
                        StartProxy::run($this->server, false);
                        $this->server->team?->notify(new ContainerRestarted('coolify-proxy', $this->server));
                    }
                } catch (\Throwable $e) {
                    ray($e);
                }
            } else {
                $this->server->proxy->status = data_get($foundProxyContainer, 'State.Status');
                $this->server->save();
                $connectProxyToDockerNetworks = connectProxyToNetworks($this->server);
                instant_remote_process($connectProxyToDockerNetworks, $this->server, false);
            }
        } catch (\Throwable $e) {
            send_internal_notification("ContainerStatusJob failed on ({$this->server->id}) with: " . $e->getMessage());
            ray($e->getMessage());
            return handleError($e);
        }
    }
}
