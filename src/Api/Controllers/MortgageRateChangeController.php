<?php

namespace Hooey\MortgageCalc\Api\Controllers;

use Carbon\Carbon;
use Hooey\MortgageCalc\MortgageRateChange;
use Hooey\MortgageCalc\MortgageSchedule;
use Hooey\MortgageCalc\MortgageValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Add / remove per-plan rate history entries on the actor's own plan. */
class MortgageRateChangeController implements RequestHandlerInterface
{
    use MortgageApiHelper;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $method = strtoupper($request->getMethod());
        $planId = $this->param($request, 'id');
        $rid = $this->param($request, 'rid');

        if ($method === 'POST' && $rid === null) {
            return $this->store($request, $actor, $planId);
        }

        if ($method === 'DELETE' && $rid !== null) {
            return $this->destroy($request, $actor, $planId, $rid);
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
        $partKey = (string) ($input['part_key'] ?? 'commercial');
        $date = trim((string) ($input['effective_date'] ?? ''));
        $rate = (float) ($input['new_rate'] ?? 0);

        try {
            $snap = MortgageSchedule::validateRateChange($this->planArray($plan), $partKey, $date, $rate);
        } catch (MortgageValidationException $e) {
            return $this->validationError($e);
        }

        // One entry per repayment date per part (later entry replaces the old one).
        MortgageRateChange::where('plan_id', $plan->id)
            ->where('part_key', $partKey)
            ->where('effective_date', $snap)
            ->delete();

        $row = MortgageRateChange::create([
            'plan_id' => (int) $plan->id,
            'part_key' => $partKey,
            'effective_date' => $snap,
            'new_rate' => round($rate, 3),
            'is_auto' => false,
            'created_at' => Carbon::now(),
        ]);

        return new JsonResponse(['data' => [
            'id' => (int) $row->id,
            'part_key' => $row->part_key,
            'effective_date' => $snap,
            'new_rate' => (float) $row->new_rate,
            'is_auto' => false,
        ]], 201);
    }

    private function destroy(ServerRequestInterface $request, $actor, $planId, $rid): ResponseInterface
    {
        $plan = $this->ownPlan($actor, $planId);

        if (!$plan) {
            return $this->error('方案不存在。', 404);
        }

        $row = MortgageRateChange::where('id', (int) $rid)->where('plan_id', $plan->id)->first();

        if (!$row) {
            return $this->error('记录不存在。', 404);
        }

        $row->delete();

        return new JsonResponse(['data' => ['deleted' => true]]);
    }
}
