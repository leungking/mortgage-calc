<?php

namespace Hooey\MortgageCalc\Api\Controllers;

use Hooey\MortgageCalc\MortgageRateFeed;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Latest reference rates (seeded defaults until a real feed lands). */
class MortgageRateFeedController implements RequestHandlerInterface
{
    use MortgageApiHelper;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->actor($request);

        if (strtoupper($request->getMethod()) !== 'GET') {
            return $this->error('Unsupported method.', 405);
        }

        return new JsonResponse(['data' => MortgageRateFeed::latest()]);
    }
}
