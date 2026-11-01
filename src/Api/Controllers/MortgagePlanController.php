<?php

namespace Hooey\MortgageCalc\Api\Controllers;

use Hooey\MortgageCalc\MortgageConversion;
use Hooey\MortgageCalc\MortgageEarlyRepay;
use Hooey\MortgageCalc\MortgagePlan;
use Hooey\MortgageCalc\MortgageRateChange;
use Hooey\MortgageCalc\MortgageRateFeed;
use Hooey\MortgageCalc\MortgageSchedule;
use Hooey\MortgageCalc\MortgageValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Personal loan plans: list / create / show / update / delete.
 * Every route is actor-scoped: guests are rejected, and a plan is only
 * visible when it belongs to the actor. Creating (or moving) a plan
 * requires a residence membership for the target house.
 */
class MortgagePlanController implements RequestHandlerInterface
{
    use MortgageApiHelper;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $method = strtoupper($request->getMethod());
        $id = $this->param($request, 'id');

        if ($method === 'GET' && $id === null) {
            return $this->index($request, $actor);
        }

        if ($method === 'POST' && $id === null) {
            return $this->store($request, $actor);
        }

        if ($method === 'GET' && $id !== null) {
            return $this->show($request, $actor, $id);
        }

        if ($method === 'PATCH' && $id !== null) {
            return $this->update($request, $actor, $id);
        }

        if ($method === 'DELETE' && $id !== null) {
            return $this->destroy($request, $actor, $id);
        }

        return $this->error('Unsupported method.', 405);
    }

    private function index(ServerRequestInterface $request, $actor): ResponseInterface
    {
        $query = $request->getQueryParams();
        $builder = MortgagePlan::where('user_id', $actor->id)->orderBy('id', 'desc');

        if (isset($query['residence_id']) && (int) $query['residence_id'] > 0) {
            $builder->where('residence_id', (int) $query['residence_id']);
        }

        $data = [];
        foreach ($builder->get() as $plan) {
            $row = $this->planArray($plan);
            $row['summary'] = $this->summaryFor($plan, false);
            $data[] = $row;
        }

        return new JsonResponse(['data' => $data]);
    }

    private function store(ServerRequestInterface $request, $actor): ResponseInterface
    {
        $input = $this->body($request);

        try {
            $feedLpr = MortgageRateFeed::latestRate('lpr_5y');
            $v = MortgageSchedule::normalize($input, $feedLpr);
        } catch (MortgageValidationException $e) {
            return $this->validationError($e);
        }

        if ($v['residence_id'] === null || !$this->hasMembership((int) $actor->id, (int) $v['residence_id'])) {
            return $this->error('只能为自己已认证的房屋创建贷款方案。', 403);
        }

        $plan = MortgagePlan::create(array_merge($v, ['user_id' => (int) $actor->id]));

        $row = $this->planArray($plan);
        $row['summary'] = $this->summaryFor($plan, false);

        return new JsonResponse(['data' => $row], 201);
    }

    private function show(ServerRequestInterface $request, $actor, $id): ResponseInterface
    {
        $plan = $this->ownPlan($actor, $id);

        if (!$plan) {
            return $this->error('方案不存在。', 404);
        }

        [$early, $convs, $rates] = $this->children($plan);
        $planArr = $this->planArray($plan);
        $summary = MortgageSchedule::summary($planArr, $early, $convs, $rates);
        $base = ($early || $convs) ? MortgageSchedule::baseSchedule($planArr) : null;

        return new JsonResponse(['data' => [
            'plan' => $planArr,
            'summary' => $summary,
            'early' => $early,
            'conversions' => $convs,
            'rate_changes' => $rates,
            'base' => $base ? [
                'total_interest' => $base['total_interest'],
                'total_payment' => $base['total_payment'],
                'end_date' => $base['end_date'],
                'count' => $base['count'],
            ] : null,
            'future_dates' => $this->futureDates($summary['schedule']),
            'has_commercial' => in_array($planArr['loan_mode'], ['commercial', 'combined'], true),
            'has_gjj' => in_array($planArr['loan_mode'], ['gjj', 'combined'], true),
        ]]);
    }

    private function update(ServerRequestInterface $request, $actor, $id): ResponseInterface
    {
        $plan = $this->ownPlan($actor, $id);

        if (!$plan) {
            return $this->error('方案不存在。', 404);
        }

        $input = $this->body($request);

        try {
            $feedLpr = MortgageRateFeed::latestRate('lpr_5y');
            $v = MortgageSchedule::normalize($input, $feedLpr);
        } catch (MortgageValidationException $e) {
            return $this->validationError($e);
        }

        if ($v['residence_id'] === null || !$this->hasMembership((int) $actor->id, (int) $v['residence_id'])) {
            return $this->error('只能为自己已认证的房屋保存贷款方案。', 403);
        }

        $plan->fill($v);
        $plan->save();

        $row = $this->planArray($plan);
        $row['summary'] = $this->summaryFor($plan, false);

        return new JsonResponse(['data' => $row]);
    }

    private function destroy(ServerRequestInterface $request, $actor, $id): ResponseInterface
    {
        $plan = $this->ownPlan($actor, $id);

        if (!$plan) {
            return $this->error('方案不存在。', 404);
        }

        MortgageEarlyRepay::where('plan_id', $plan->id)->delete();
        MortgageConversion::where('plan_id', $plan->id)->delete();
        MortgageRateChange::where('plan_id', $plan->id)->delete();
        $plan->delete();

        return new JsonResponse(['data' => ['deleted' => true]]);
    }
}
