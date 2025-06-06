<?php

/**
 * OtherCurrenciesCorrections.php
 * Copyright (c) 2020 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Upgrade;

use FireflyIII\Console\Commands\ShowsFriendlyMessages;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Journal\JournalCLIRepositoryInterface;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use Illuminate\Console\Command;

class UpgradesVariousCurrencyInformation extends Command
{
    use ShowsFriendlyMessages;

    public const string CONFIG_NAME = '480_other_currencies';
    protected $description          = 'Update all journal currency information.';
    protected $signature            = 'upgrade:480-currency-information {--F|force : Force the execution of this command.}';
    private array                         $accountCurrencies;
    private AccountRepositoryInterface    $accountRepos;
    private JournalCLIRepositoryInterface $cliRepos;
    private int                           $count;
    private JournalRepositoryInterface    $journalRepos;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->stupidLaravel();

        if ($this->isExecuted() && true !== $this->option('force')) {
            $this->friendlyInfo('This command has already been executed.');

            return 0;
        }

        $this->updateOtherJournalsCurrencies();
        $this->markAsExecuted();

        $this->friendlyPositive('Verified and fixed transaction currencies.');

        return 0;
    }

    /**
     * Laravel will execute ALL __construct() methods for ALL commands whenever a SINGLE command is
     * executed. This leads to noticeable slow-downs and class calls. To prevent this, this method should
     * be called from the handle method instead of using the constructor to initialize the command.
     */
    private function stupidLaravel(): void
    {
        $this->count             = 0;
        $this->accountCurrencies = [];
        $this->accountRepos      = app(AccountRepositoryInterface::class);
        $this->journalRepos      = app(JournalRepositoryInterface::class);
        $this->cliRepos          = app(JournalCLIRepositoryInterface::class);
    }

    private function isExecuted(): bool
    {
        $configVar = app('fireflyconfig')->get(self::CONFIG_NAME, false);
        if (null !== $configVar) {
            return (bool) $configVar->data;
        }

        return false;
    }

    /**
     * This routine verifies that withdrawals, deposits and opening balances have the correct currency settings for
     * the accounts they are linked to.
     * Both source and destination must match the respective currency preference of the related asset account.
     * So FF3 must verify all transactions.
     */
    private function updateOtherJournalsCurrencies(): void
    {
        $set = $this->cliRepos->getAllJournals(
            [TransactionTypeEnum::WITHDRAWAL->value, TransactionTypeEnum::DEPOSIT->value, TransactionTypeEnum::OPENING_BALANCE->value, TransactionTypeEnum::RECONCILIATION->value]
        );

        /** @var TransactionJournal $journal */
        foreach ($set as $journal) {
            $this->updateJournalCurrency($journal);
        }
    }

    private function updateJournalCurrency(TransactionJournal $journal): void
    {
        $this->accountRepos->setUser($journal->user);
        $this->journalRepos->setUser($journal->user);
        $this->cliRepos->setUser($journal->user);

        $leadTransaction = $this->getLeadTransaction($journal);

        if (!$leadTransaction instanceof Transaction) {
            $this->friendlyError(sprintf('Could not reliably determine which transaction is in the lead for transaction journal #%d.', $journal->id));

            return;
        }

        $account         = $leadTransaction->account;
        $currency        = $this->getCurrency($account);
        $isMultiCurrency = $this->isMultiCurrency($account);
        if (!$currency instanceof TransactionCurrency) {
            $this->friendlyError(
                sprintf(
                    'Account #%d ("%s") has no currency preference, so transaction journal #%d can\'t be corrected',
                    $account->id,
                    $account->name,
                    $journal->id
                )
            );
            ++$this->count;

            return;
        }
        // fix each transaction:
        $journal->transactions->each(
            static function (Transaction $transaction) use ($currency, $isMultiCurrency): void {
                if (null === $transaction->transaction_currency_id) {
                    $transaction->transaction_currency_id = $currency->id;
                    $transaction->save();
                }

                // when mismatch in transaction:
                if ($transaction->transaction_currency_id !== $currency->id && !$isMultiCurrency) {
                    $transaction->foreign_currency_id     = $transaction->transaction_currency_id;
                    $transaction->foreign_amount          = $transaction->amount;
                    $transaction->transaction_currency_id = $currency->id;
                    $transaction->save();
                }
            }
        );
        // also update the journal, of course:
        if (!$isMultiCurrency) {
            $journal->transaction_currency_id = $currency->id;
        }
        ++$this->count;
        $journal->save();
    }

    /**
     * Gets the transaction that determines the transaction that "leads" and will determine
     * the currency to be used by all transactions, and the journal itself.
     */
    private function getLeadTransaction(TransactionJournal $journal): ?Transaction
    {
        /** @var null|Transaction $lead */
        $lead = null;

        switch ($journal->transactionType->type) {
            default:
                break;

            case TransactionTypeEnum::WITHDRAWAL->value:
                $lead = $journal->transactions()->where('amount', '<', 0)->first();

                break;

            case TransactionTypeEnum::DEPOSIT->value:
                $lead = $journal->transactions()->where('amount', '>', 0)->first();

                break;

            case TransactionTypeEnum::OPENING_BALANCE->value:
                // whichever isn't an initial balance account:
                $lead = $journal->transactions()->leftJoin('accounts', 'transactions.account_id', '=', 'accounts.id')->leftJoin(
                    'account_types',
                    'accounts.account_type_id',
                    '=',
                    'account_types.id'
                )->where('account_types.type', '!=', AccountTypeEnum::INITIAL_BALANCE->value)->first(['transactions.*']);

                break;

            case TransactionTypeEnum::RECONCILIATION->value:
                // whichever isn't the reconciliation account:
                $lead = $journal->transactions()->leftJoin('accounts', 'transactions.account_id', '=', 'accounts.id')->leftJoin(
                    'account_types',
                    'accounts.account_type_id',
                    '=',
                    'account_types.id'
                )->where('account_types.type', '!=', AccountTypeEnum::RECONCILIATION->value)->first(['transactions.*']);

                break;
        }

        /** @var null|Transaction */
        return $lead;
    }

    private function getCurrency(Account $account): ?TransactionCurrency
    {
        $accountId                           = $account->id;
        if (array_key_exists($accountId, $this->accountCurrencies) && 0 === $this->accountCurrencies[$accountId]) {
            return null;
        }
        if (array_key_exists($accountId, $this->accountCurrencies) && $this->accountCurrencies[$accountId] instanceof TransactionCurrency) {
            return $this->accountCurrencies[$accountId];
        }
        $currency                            = $this->accountRepos->getAccountCurrency($account);
        if (!$currency instanceof TransactionCurrency) {
            $this->accountCurrencies[$accountId] = 0;

            return null;
        }
        $this->accountCurrencies[$accountId] = $currency;

        return $currency;
    }

    private function isMultiCurrency(Account $account): bool
    {
        $value = $this->accountRepos->getMetaValue($account, 'is_multi_currency');
        if (null === $value) {
            return false;
        }

        return '1' === $value;
    }

    private function markAsExecuted(): void
    {
        app('fireflyconfig')->set(self::CONFIG_NAME, true);
    }
}
