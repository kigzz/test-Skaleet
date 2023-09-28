<?php

namespace Skaleet\Interview\TransactionProcessing\UseCase;

use Skaleet\Interview\TransactionProcessing\Domain\AccountRegistry;
use Skaleet\Interview\TransactionProcessing\Domain\Model\Account;
use Skaleet\Interview\TransactionProcessing\Domain\Model\AccountingEntry;
use Skaleet\Interview\TransactionProcessing\Domain\Model\Amount;
use Skaleet\Interview\TransactionProcessing\Domain\Model\TransactionLog;
use Skaleet\Interview\TransactionProcessing\Domain\TransactionRepository;
use Skaleet\Interview\TransactionProcessing\Infrastructure\ExistingAccounts;

class PayByCardCommandHandler
{
    public function __construct(
        private TransactionRepository $transactionRepository,
        private AccountRegistry       $accountRegistry,
    )
    {
    }

    public function handle(PayByCardCommand $command): void
    {
        // Access existing account numbers

        $clientAccountNumberEuro = ExistingAccounts::CLIENT_EUR;
        $clientAccountNumberUsd = ExistingAccounts::CLIENT_USD;
        $merchantAccountNumberEuro = ExistingAccounts::MERCHANT_EUR;
        $merchantAccountNumberUsd = ExistingAccounts::MERCHANT_USD;

        $transaction = new Amount($command->amount, $command->currency);

        // Balance accounts + amount pay before paying

        $client = $this->accountRegistry->loadByNumber($command->clientAccountNumber);
        $merchant = $this->accountRegistry->loadByNumber($command->merchantAccountNumber);

        // Check currency match between account when trading

        if ($transaction->currency !== "EUR" && $transaction->currency !== "USD") {
            throw new \InvalidArgumentException("Invalid currency\n");
        }

        if ($transaction->currency == "EUR") {
            if ($command->clientAccountNumber !== $clientAccountNumberEuro) {
                throw new \InvalidArgumentException("Invalid arguments !\n");
            }
            if ($command->merchantAccountNumber !== $merchantAccountNumberEuro) {
                throw new \InvalidArgumentException("Invalid arguments !\n");
            }
        }

        else {
            if ($command->clientAccountNumber !== $clientAccountNumberUsd) {
                throw new \InvalidArgumentException("Invalid arguments !\n");
            }
            if ($command->merchantAccountNumber !== $merchantAccountNumberUsd) {
                throw new \InvalidArgumentException("Invalid arguments !\n");
            }
        }

        // Check if $amount > 0

        if ($transaction->value <= 0) {
            throw new \InvalidArgumentException("Amount must be strictly positive.\n");
        }

        // Check if client balance is enough
        else if ($client->balance->value < $transaction->value) {
            throw new \InvalidArgumentException("Insufficient client credit \n");
        }

        // Saving transactions

        $client->balance->value -= $transaction->value;
        $merchant->balance->value += $transaction->value;

        $debitAmount = new Amount(-$transaction->value, $transaction->currency);
        $creditAmount = new Amount($transaction->value, $transaction->currency);

        $debitEntry = new AccountingEntry($command->clientAccountNumber, $debitAmount, $client->balance);
        $creditEntry = new AccountingEntry($command->merchantAccountNumber, $creditAmount, $merchant->balance);

        $accountingEntries = [$debitEntry, $creditEntry];
        $transactionLog = new TransactionLog($this->generateUniqueTransactionId(),
                new \DateTimeImmutable(), $accountingEntries);

        $this->transactionRepository->add($transactionLog);
    }

    private function generateUniqueTransactionId(): string {
        return uniqid('tr_', true);
    }
}
