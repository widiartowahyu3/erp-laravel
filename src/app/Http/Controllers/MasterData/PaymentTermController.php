<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\PaymentTermStoreRequest;
use App\Http\Requests\MasterData\PaymentTermUpdateRequest;
use App\Models\PaymentTerm;
use App\Services\MasterData\PaymentTermService;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PaymentTermController extends Controller
{
    public function __construct(
        private readonly PaymentTermService $paymentTermService
    ) {}

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(PaymentTerm::query())
            ->searchable(['code', 'name'])
            ->sortable(['id', 'code', 'name', 'days', 'created_at'])
            ->defaultSort('days', 'asc')
            ->paginate($request, 25);

        return Inertia::render('MasterData/PaymentTerms/Index', [
            'items' => Paginated::from($paginator, null, $request),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MasterData/PaymentTerms/Create');
    }

    public function store(PaymentTermStoreRequest $request): RedirectResponse
    {
        $this->paymentTermService->create($request->validated());

        return redirect()
            ->route('master.payment-terms.index')
            ->with('success', 'Syarat pembayaran berhasil ditambahkan.');
    }

    public function edit(PaymentTerm $paymentTerm): Response
    {
        return Inertia::render('MasterData/PaymentTerms/Edit', [
            'item' => $paymentTerm,
        ]);
    }

    public function update(PaymentTermUpdateRequest $request, PaymentTerm $paymentTerm): RedirectResponse
    {
        $this->paymentTermService->update($paymentTerm, $request->validated());

        return redirect()
            ->route('master.payment-terms.index')
            ->with('success', 'Syarat pembayaran berhasil diperbarui.');
    }

    public function destroy(Request $request, PaymentTerm $paymentTerm): JsonResponse|RedirectResponse
    {
        try {
            $this->paymentTermService->delete($paymentTerm);

            return back()->with('success', 'Syarat pembayaran berhasil dihapus.');
        } catch (BusinessRuleException $e) {
            $message = 'Tidak dapat menghapus data: '.$e->getMessage();

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['form' => [$message]],
                ], 409);
            }

            return back()->with('error', $message)->setStatusCode(409);
        }
    }
}
