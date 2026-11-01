<?php

namespace Hooey\MortgageCalc\Api\Controllers;

use Carbon\Carbon;
use Hooey\MortgageCalc\MortgageEarlyRepay;
use Hooey\MortgageCalc\MortgageSchedule;
use Hooey\MortgageCalc\MortgageValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Add / remove early-repayment trials on the actor's own plan. */
class MortgageEarlyRepayController implements RequestHandlerInterface
{
    use MortgageApiHelper;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $method = strtoupper($request->getMethod());
        $planId = $this->param($request, 'id');
        $eid = $this->param($request, 'eid');

        if ($method === 'POST' && $eid === null) {
            return $this->store($request, $actor, $planId);
        }

        if ($method === 'DELETE' && $eid !== null) {
            return $this->destroy($request, $actor, $planId, $eid);
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
        $date = trim((string) ($input['repay_date'] ?? ''));
        $amount = (float) ($input['amount'] ?? 0);
        $mode = (string) ($input['mode'] ?? 'reduce_payment');

        try {
            [$early, $convs, $rates] = $this->children($plan);
            $schedule = MortgageSchedule::buildSchedule($this->planArray($plan), $early, $convs, $rates);
            MortgageSchedule::validateEarlyRepay($this->planArray($plan), $schedule, $date, $amount, $mode);
        } catch (MortgageValidationException $e) {
            return $this->validationError($e);
        }

        $row = MortgageEarlyRepay::create([
            'plan_id' => (int) $plan->id,
            'repay_date' => $date,
            'amount' => round($amount, 2),
            'mode' => $mode,
            'created_at' => Carbon::now(),
        ]);

        return new JsonResponse(['data' => [
            'id' => (int) $row->id,
            'repay_date' => $date,
            'amount' => (float) $row->amount,
            'mode' => $row->mode,
        ]], 201);
    }

    private function destroy(ServerRequestInterface $request, $actor, $planId, $eid): ResponseInterface
    {
        $plan = $this->ownPlan($actor, $planId);

        if (!$plan) {
            return $this->error('方案不存在。', 404);
        }

        $row = MortgageEarlyRepay::where('id', (int) $eid)->where('plan_id', $plan->id)->first();

        if (!$row) {
            return $this->error('记录不存在。', 404);
        }

        $row->delete();

        return new JsonResponse(['data' => ['deleted' => true]]);
    }
}
