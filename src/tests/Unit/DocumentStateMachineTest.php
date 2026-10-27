<?php

declare(strict_types=1);

use App\Enums\DeliveryOrderStatus;
use App\Enums\DocumentType;
use App\Enums\GoodsReceiptStatus;
use App\Enums\JournalStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockAdjustmentStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Services\Documents\DocumentStateMachine;

test('PurchaseOrderStatus transition matrix enforces valid transitions and rejects invalid ones', function () {
    $validTransitions = [
        [PurchaseOrderStatus::DRAFT, PurchaseOrderStatus::APPROVED],
        [PurchaseOrderStatus::DRAFT, PurchaseOrderStatus::VOID],
        [PurchaseOrderStatus::APPROVED, PurchaseOrderStatus::PARTIALLY_RECEIVED],
        [PurchaseOrderStatus::APPROVED, PurchaseOrderStatus::RECEIVED],
        [PurchaseOrderStatus::APPROVED, PurchaseOrderStatus::VOID],
        [PurchaseOrderStatus::PARTIALLY_RECEIVED, PurchaseOrderStatus::RECEIVED],
        [PurchaseOrderStatus::PARTIALLY_RECEIVED, PurchaseOrderStatus::CLOSED],
        [PurchaseOrderStatus::RECEIVED, PurchaseOrderStatus::CLOSED],
    ];

    foreach (PurchaseOrderStatus::cases() as $from) {
        foreach (PurchaseOrderStatus::cases() as $to) {
            $isValid = false;
            foreach ($validTransitions as [$vFrom, $vTo]) {
                if ($from === $vFrom && $to === $vTo) {
                    $isValid = true;
                    break;
                }
            }

            expect($from->canTransitionTo($to))->toBe($isValid)
                ->and(DocumentStateMachine::canTransition($from, $to))->toBe($isValid);

            if ($isValid) {
                DocumentStateMachine::assertTransition($from, $to);
            } else {
                expect(fn () => DocumentStateMachine::assertTransition($from, $to))
                    ->toThrow(InvalidStateTransitionException::class);
            }
        }
    }
});

test('SalesOrderStatus transition matrix enforces valid transitions and rejects invalid ones', function () {
    $validTransitions = [
        [SalesOrderStatus::DRAFT, SalesOrderStatus::APPROVED],
        [SalesOrderStatus::DRAFT, SalesOrderStatus::VOID],
        [SalesOrderStatus::APPROVED, SalesOrderStatus::PARTIALLY_DELIVERED],
        [SalesOrderStatus::APPROVED, SalesOrderStatus::DELIVERED],
        [SalesOrderStatus::APPROVED, SalesOrderStatus::VOID],
        [SalesOrderStatus::PARTIALLY_DELIVERED, SalesOrderStatus::DELIVERED],
        [SalesOrderStatus::PARTIALLY_DELIVERED, SalesOrderStatus::CLOSED],
        [SalesOrderStatus::DELIVERED, SalesOrderStatus::CLOSED],
    ];

    foreach (SalesOrderStatus::cases() as $from) {
        foreach (SalesOrderStatus::cases() as $to) {
            $isValid = false;
            foreach ($validTransitions as [$vFrom, $vTo]) {
                if ($from === $vFrom && $to === $vTo) {
                    $isValid = true;
                    break;
                }
            }

            expect($from->canTransitionTo($to))->toBe($isValid)
                ->and(DocumentStateMachine::canTransition($from, $to))->toBe($isValid);

            if ($isValid) {
                DocumentStateMachine::assertTransition($from, $to);
            } else {
                expect(fn () => DocumentStateMachine::assertTransition($from, $to))
                    ->toThrow(InvalidStateTransitionException::class);
            }
        }
    }
});

test('Draft-Posted-Void document status enums transition matrix', function () {
    $enums = [
        GoodsReceiptStatus::class,
        StockAdjustmentStatus::class,
        DeliveryOrderStatus::class,
        PaymentStatus::class,
        JournalStatus::class,
    ];

    foreach ($enums as $enumClass) {
        /** @var array<object> $cases */
        $cases = $enumClass::cases();

        foreach ($cases as $from) {
            foreach ($cases as $to) {
                $fromVal = $from->value;
                $toVal = $to->value;

                $isValid = ($fromVal === 'draft' && in_array($toVal, ['posted', 'void'], true))
                    || ($fromVal === 'posted' && $toVal === 'void');

                expect($from->canTransitionTo($to))->toBe($isValid)
                    ->and(DocumentStateMachine::canTransition($from, $to))->toBe($isValid);

                if ($isValid) {
                    DocumentStateMachine::assertTransition($from, $to);
                } else {
                    expect(fn () => DocumentStateMachine::assertTransition($from, $to))
                        ->toThrow(InvalidStateTransitionException::class);
                }
            }
        }
    }
});

test('SalesInvoice and PurchaseInvoice transition matrices handle partial payments and reversals', function () {
    $invoiceEnums = [SalesInvoiceStatus::class, PurchaseInvoiceStatus::class];

    foreach ($invoiceEnums as $enumClass) {
        $validPairs = [
            ['draft', 'posted'],
            ['draft', 'void'],
            ['posted', 'partially_paid'],
            ['posted', 'paid'],
            ['posted', 'void'],
            ['partially_paid', 'paid'],
            ['partially_paid', 'posted'],
            ['paid', 'partially_paid'],
            ['paid', 'posted'],
        ];

        /** @var array<object> $cases */
        $cases = $enumClass::cases();

        foreach ($cases as $from) {
            foreach ($cases as $to) {
                $isValid = false;
                foreach ($validPairs as [$vf, $vt]) {
                    if ($from->value === $vf && $to->value === $vt) {
                        $isValid = true;
                        break;
                    }
                }

                expect($from->canTransitionTo($to))->toBe($isValid)
                    ->and(DocumentStateMachine::canTransition($from, $to))->toBe($isValid);

                if ($isValid) {
                    DocumentStateMachine::assertTransition($from, $to);
                } else {
                    expect(fn () => DocumentStateMachine::assertTransition($from, $to))
                        ->toThrow(InvalidStateTransitionException::class);
                }
            }
        }
    }
});

test('DocumentStateMachine rejects transitions between mismatched enum types', function () {
    expect(DocumentStateMachine::canTransition(PurchaseOrderStatus::DRAFT, SalesOrderStatus::DRAFT))->toBeFalse();

    expect(fn () => DocumentStateMachine::assertTransition(PurchaseOrderStatus::DRAFT, SalesOrderStatus::APPROVED))
        ->toThrow(InvalidStateTransitionException::class);
});

test('all enums have non-empty Indonesian human-readable labels', function () {
    $enums = [
        PurchaseOrderStatus::class,
        SalesOrderStatus::class,
        GoodsReceiptStatus::class,
        StockAdjustmentStatus::class,
        DeliveryOrderStatus::class,
        PaymentStatus::class,
        JournalStatus::class,
        SalesInvoiceStatus::class,
        PurchaseInvoiceStatus::class,
        DocumentType::class,
    ];

    foreach ($enums as $enumClass) {
        foreach ($enumClass::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
        }
    }
});
