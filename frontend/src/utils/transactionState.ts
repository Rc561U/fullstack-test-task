import type { Transaction } from "../types";

export function updateTransactionAfterRefund(
  transactions: Transaction[],
  transactionId: number,
  refundAmount: string,
): Transaction[] {
  return transactions.map((tx) => {
    if (tx.id !== transactionId) {
      return tx;
    }

    const newRefundedAmount = (
      parseFloat(tx.refundedAmount) + parseFloat(refundAmount)
    ).toFixed(2);
    const isFullyRefunded =
      parseFloat(newRefundedAmount) >= parseFloat(tx.amount);

    return {
      ...tx,
      refundedAmount: newRefundedAmount,
      status: isFullyRefunded ? "refunded" : "partially_refunded",
    };
  });
}
