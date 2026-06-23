import { useState } from "react";
import type { Transaction } from "../types";
import { RefundModal } from "./RefundModal";

interface TransactionsTableProps {
  transactions: Transaction[];
  onTransactionRefunded: (transactionId: number, refundAmount: string) => void;
}

const REFUNDABLE_STATUSES = new Set(["paid", "settled", "partially_refunded"]);

function canRefund(transaction: Transaction): boolean {
  if (!REFUNDABLE_STATUSES.has(transaction.status.toLowerCase())) {
    return false;
  }

  const refundable =
    parseFloat(transaction.amount) - parseFloat(transaction.refundedAmount);
  return refundable > 0;
}

export function TransactionsTable({
  transactions,
  onTransactionRefunded,
}: TransactionsTableProps) {
  const [refundTransaction, setRefundTransaction] = useState<Transaction | null>(null);

  return (
    <>
      <table className="tx-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Merchant</th>
            <th className="num">Amount</th>
            <th>Currency</th>
            <th className="num">Fee</th>
            <th>Status</th>
            <th className="num">Refunded</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          {transactions.map((tx) => (
            <tr key={tx.id}>
              <td>{tx.id}</td>
              <td>{tx.merchantName}</td>
              <td className="num">{tx.amount}</td>
              <td>{tx.currency}</td>
              <td className="num">{tx.feeDisplayed}</td>
              <td>
                <span className={`status status-${tx.status.toLowerCase()}`}>
                  {tx.status}
                </span>
              </td>
              <td className="num">{tx.refundedAmount}</td>
              <td>{new Date(tx.createdAt).toLocaleString()}</td>
              <td>
                <button
                  type="button"
                  className="refund-btn"
                  onClick={() => setRefundTransaction(tx)}
                  disabled={!canRefund(tx)}
                >
                  Возврат
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <RefundModal
        transaction={refundTransaction}
        onClose={() => setRefundTransaction(null)}
        onSuccess={onTransactionRefunded}
      />
    </>
  );
}
