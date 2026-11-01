<?php

namespace Hooey\MortgageCalc\Api\Controllers;

use Carbon\Carbon;
use Hooey\MortgageCalc\MortgageConversion;
use Hooey\MortgageCalc\MortgageSchedule;
use Hooey\MortgageCalc\MortgageValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Add / remove commercial-to-fund conversion trials on the actor's own plan. */
class MortgageConversionController implements RequestHandlerInterface
{
    use MortgageApiHelper;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $method = strtoupper($request->getMethod());
        $planId = $this->param($request, 'id');
        $cid = $this->param($request, 'cid');

        if ($method === 'POST' && $cid === null) {
            return $this->store($request, $actor, $planId);
        }

        if ($method === 'DELETE' && $cid !== null) {
            return $this->destroy($request, $actor, $planId, $cid);
        }

        return $this->error('Unsupported method.', 405);
    }

    private function store(ServerRequestInterface $request, $actor, $planId): ResponseInterface
    {
        $plan = $this->ownPlan($actor, $planId);

        if (!$plan) {
            return $this->error('方案不存在。', 404);
        }

        $input = $this->body($request);
        $date = trim((string) ($input['convert_date'] ?? ''));
        $amount = (float) ($input['amount'] ?? 0);
        $gjjRate = max(0, min(20, round((float) ($input['gjj_rate'] ?? MortgageSchedule::DEFAULT_GJJ_RATE), 3)));

        try {
            [$early, $convs, $rates] = $this->children($plan);
            $schedule = MortgageSchedule::buildSchedule($this->planArray($plan), $early, $convs, $rates);
            MortgageSchedule::validateConversion($this->planArray($plan), $schedule, $date, $amount);
        } catch (MortgageValidationException $e) {
            return $this->validationError($e);
        }

        $row = MortgageConversion::create([
            'plan_id' => (int) $plan->id,
            'convert_date' => $date,
            'amount' => round($amount, 2),
            'gjj_rate' => $gjjRate,
            'created_at' => Carbon::now(),
        ]);

        return new JsonResponse(['data' => [
            'id' => (int) $row->id,
            'convert_date' => $date,
            'amount' => (float) $row->amount,
            'gjj_rate' => (float) $row->gjj_rate,
        ]], 201);
    }

    private function destroy(ServerRequestInterface $request, $actor, $planId, $cid): ResponseInterface
    {
        $plan = $this->ownPlan($actor, $planId);

        if (!$plan) {
            return $this->error('方案不存在。', 404);
        }

        $row = MortgageConversion::where('id', (int) $cid)->where('plan_id', $plan->id)->first();

        if (!$row) {
            return $this->error('记录不存在。', 404);
        }

        $row->delete();

        return new JsonResponse(['data' => ['deleted' => true]]);
    }
}
