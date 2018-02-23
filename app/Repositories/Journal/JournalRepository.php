<?php
/**
 * JournalRepository.php
 * Copyright (c) 2017 thegrumpydictator@gmail.com
 *
 * This file is part of Firefly III.
 *
 * Firefly III is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Firefly III is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Firefly III. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace FireflyIII\Repositories\Journal;

use Exception;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Factory\TransactionJournalFactory;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Note;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use FireflyIII\Services\Internal\Destroy\JournalDestroyService;
use FireflyIII\Services\Internal\Update\JournalUpdateService;
use FireflyIII\User;
use Illuminate\Support\Collection;
use Illuminate\Support\MessageBag;
use Log;
use Preferences;

/**
 * Class JournalRepository.
 */
class JournalRepository implements JournalRepositoryInterface
{
    /** @var User */
    private $user;

    /**
     * @param TransactionJournal $journal
     * @param TransactionType    $type
     * @param Account            $source
     * @param Account            $destination
     *
     * @return MessageBag
     */
    public function convert(TransactionJournal $journal, TransactionType $type, Account $source, Account $destination): MessageBag
    {
        // default message bag that shows errors for everything.
        $messages = new MessageBag;
        $messages->add('source_account_revenue', trans('firefly.invalid_convert_selection'));
        $messages->add('destination_account_asset', trans('firefly.invalid_convert_selection'));
        $messages->add('destination_account_expense', trans('firefly.invalid_convert_selection'));
        $messages->add('source_account_asset', trans('firefly.invalid_convert_selection'));

        if ($source->id === $destination->id || null === $source->id || null === $destination->id) {
            return $messages;
        }

        $sourceTransaction             = $journal->transactions()->where('amount', '<', 0)->first();
        $destinationTransaction        = $journal->transactions()->where('amount', '>', 0)->first();
        $sourceTransaction->account_id = $source->id;
        $sourceTransaction->save();
        $destinationTransaction->account_id = $destination->id;
        $destinationTransaction->save();
        $journal->transaction_type_id = $type->id;
        $journal->save();

        // if journal is a transfer now, remove budget:
        if (TransactionType::TRANSFER === $type->type) {
            $journal->budgets()->detach();
        }

        Preferences::mark();

        return new MessageBag;
    }

    /**
     * @param TransactionJournal $journal
     *
     * @return int
     */
    public function countTransactions(TransactionJournal $journal): int
    {
        return $journal->transactions()->count();
    }

    /**
     * @param TransactionJournal $journal
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function destroy(TransactionJournal $journal): bool
    {
        /** @var JournalDestroyService $service */
        $service = app(JournalDestroyService::class);
        $service->destroy($journal);

        return true;
    }

    /**
     * @param int $journalId
     *
     * @return TransactionJournal
     */
    public function find(int $journalId): TransactionJournal
    {
        /** @var TransactionJournal $journal */
        $journal = $this->user->transactionJournals()->where('id', $journalId)->first();
        if (null === $journal) {
            return new TransactionJournal;
        }

        return $journal;
    }

    /**
     * @param Transaction $transaction
     *
     * @return Transaction|null
     */
    public function findOpposingTransaction(Transaction $transaction): ?Transaction
    {
        $opposing = Transaction::leftJoin('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
                               ->where('transaction_journals.user_id', $this->user->id)
                               ->where('transactions.transaction_journal_id', $transaction->transaction_journal_id)
                               ->where('transactions.identifier', $transaction->identifier)
                               ->where('amount', bcmul($transaction->amount, '-1'))
                               ->first(['transactions.*']);

        return $opposing;
    }

    /**
     * @param int $transactionid
     *
     * @return Transaction|null
     */
    public function findTransaction(int $transactionid): ?Transaction
    {
        $transaction = Transaction::leftJoin('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
                                  ->where('transaction_journals.user_id', $this->user->id)
                                  ->where('transactions.id', $transactionid)
                                  ->first(['transactions.*']);

        return $transaction;
    }

    /**
     * Get users first transaction journal.
     *
     * @return TransactionJournal
     */
    public function first(): TransactionJournal
    {
        /** @var TransactionJournal $entry */
        $entry = $this->user->transactionJournals()->orderBy('date', 'ASC')->first(['transaction_journals.*']);

        if (null === $entry) {
            return new TransactionJournal;
        }

        return $entry;
    }

    /**
     * @param TransactionJournal $journal
     *
     * @return Transaction|null
     */
    public function getAssetTransaction(TransactionJournal $journal): ?Transaction
    {
        /** @var Transaction $transaction */
        foreach ($journal->transactions as $transaction) {
            if (AccountType::ASSET === $transaction->account->accountType->type) {
                return $transaction;
            }
        }

        return null;
    }

    /**
     * @param TransactionJournal $journal
     *
     * @return Note|null
     */
    public function getNote(TransactionJournal $journal): ?Note
    {
        return $journal->notes()->first();
    }

    /**
     * @return Collection
     */
    public function getTransactionTypes(): Collection
    {
        return TransactionType::orderBy('type', 'ASC')->get();
    }

    /**
     * @param array $transactionIds
     *
     * @return Collection
     */
    public function getTransactionsById(array $transactionIds): Collection
    {
        $set = Transaction::leftJoin('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
                          ->whereIn('transactions.id', $transactionIds)
                          ->where('transaction_journals.user_id', $this->user->id)
                          ->whereNull('transaction_journals.deleted_at')
                          ->whereNull('transactions.deleted_at')
                          ->get(['transactions.*']);

        return $set;
    }

    /**
     * @param Transaction $transaction
     *
     * @return bool
     */
    public function reconcile(Transaction $transaction): bool
    {
        Log::debug(sprintf('Going to reconcile transaction #%d', $transaction->id));
        $opposing = $this->findOpposingTransaction($transaction);

        if (null === $opposing) {
            Log::debug('Opposing transaction is NULL. Cannot reconcile.');

            return false;
        }
        Log::debug(sprintf('Opposing transaction ID is #%d', $opposing->id));

        $transaction->reconciled = true;
        $opposing->reconciled    = true;
        $transaction->save();
        $opposing->save();

        return true;
    }

    /**
     * @param TransactionJournal $journal
     * @param int                $order
     *
     * @return bool
     */
    public function setOrder(TransactionJournal $journal, int $order): bool
    {
        $journal->order = $order;
        $journal->save();

        return true;
    }

    /**
     * @param User $user
     */
    public function setUser(User $user)
    {
        $this->user = $user;
    }

    /**
     * @param array $data
     *
     * @return TransactionJournal
     *
     * @throws \FireflyIII\Exceptions\FireflyException
     * @throws \FireflyIII\Exceptions\FireflyException
     */
    public function store(array $data): TransactionJournal
    {
        /** @var TransactionJournalFactory $factory */
        $factory = app(TransactionJournalFactory::class);
        $factory->setUser($this->user);

        return $factory->create($data);
    }

    /**
     * @param TransactionJournal $journal
     * @param array              $data
     *
     * @return TransactionJournal
     *
     * @throws \FireflyIII\Exceptions\FireflyException
     */
    public function update(TransactionJournal $journal, array $data): TransactionJournal
    {
        /** @var JournalUpdateService $service */
        $service = app(JournalUpdateService::class);
        $service->setUser($this->user);

        try {
            $journal = $service->update($journal, $data);
        } catch (FireflyException | Exception $e) {
            throw new FireflyException($e->getMessage());
        }

        return $journal;
    }

    /**
     * Get account of transaction that is more than zero. Only works with unsplit journals.
     *
     * @param TransactionJournal $journal
     *
     * @return Account
     */
    public function getDestinationAccount(TransactionJournal $journal): Account
    {
        return $journal->transactions()->where('amount','<',0)->first()->account;
    }

    /**
     * Get account of transaction that is less than zero. Only works with unsplit journals.
     *
     * @param TransactionJournal $journal
     *
     * @return Account
     */
    public function getSourceAccount(TransactionJournal $journal): Account
    {
        return $journal->transactions()->where('amount','>',0)->first()->account;
    }
}
