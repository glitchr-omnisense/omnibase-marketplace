<?php

namespace Base\Marketplace\Enum;

/**
 * What a product is, for what happens once it is paid:
 *
 *   GOODS        something delivered or downloaded (the default);
 *   PLAN         a right of access - a pass bought once or a subscription -:
 *                paid, it grants an Entitlement (Model\PlanTerms says which);
 *   CREDIT_PACK  units to spend later (answers of an assistant, hours,
 *                stationery): paid, it credits the buyer's ledger.
 */
enum ProductKind: string
{
    case GOODS = 'goods';
    case PLAN = 'plan';
    case CREDIT_PACK = 'credit_pack';
}
