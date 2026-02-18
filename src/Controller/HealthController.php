<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(Connection $connection): JsonResponse
    {
        $checks = [
            'status' => 'healthy',
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'checks' => []
        ];

        // Check database connection
        try {
            $connection->executeQuery('SELECT 1');
            $checks['checks']['database'] = 'ok';
        } catch (\Exception $e) {
            $checks['status'] = 'unhealthy';
            $checks['checks']['database'] = 'failed: ' . $e->getMessage();
        }

        // Check if cache directory is writable
        $cacheDir = $this->getParameter('kernel.cache_dir');
        if (is_writable($cacheDir)) {
            $checks['checks']['cache_writable'] = 'ok';
        } else {
            $checks['status'] = 'unhealthy';
            $checks['checks']['cache_writable'] = 'failed';
        }

        // Check if log directory is writable
        $logDir = $this->getParameter('kernel.logs_dir');
        if (is_writable($logDir)) {
            $checks['checks']['logs_writable'] = 'ok';
        } else {
            $checks['status'] = 'unhealthy';
            $checks['checks']['logs_writable'] = 'failed';
        }

        // Add application info
        $checks['app'] = [
            'name' => 'Smart Scheduling System',
            'environment' => $this->getParameter('kernel.environment'),
            'debug' => $this->getParameter('kernel.debug')
        ];

        $statusCode = $checks['status'] === 'healthy' ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse($checks, $statusCode);
    }

    #[Route('/health/simple', name: 'app_health_simple', methods: ['GET'])]
    public function simpleHealth(): Response
    {
        // Simple health check that just returns 200 OK
        // Useful for load balancers that don't need detailed info
        return new Response('OK', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }


}
