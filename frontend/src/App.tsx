import { useCallback, useEffect, useState } from "react";
import { fetchTransactions } from "./api/client";
import { TransactionsTable } from "./components/TransactionsTable";
import type { Transaction } from "./types";
import { updateTransactionAfterRefund } from "./utils/transactionState";
import "./App.css";

export default function App() {
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    fetchTransactions()
      .then((data) => {
        if (!cancelled) setTransactions(data);
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : "Unknown error");
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  const handleTransactionRefunded = useCallback(
    (transactionId: number, refundAmount: string) => {
      setTransactions((current) =>
        updateTransactionAfterRefund(current, transactionId, refundAmount),
      );
    },
    [],
  );

  return (
    <main className="app">
      <h1>Transactions</h1>

      {loading && <p className="state">Loading...</p>}
      {error && <p className="state state-error">Error: {error}</p>}

      {!loading && !error && transactions.length === 0 && (
        <p className="state">No transactions found.</p>
      )}

      {!loading && !error && transactions.length > 0 && (
        <TransactionsTable
          transactions={transactions}
          onTransactionRefunded={handleTransactionRefunded}
        />
      )}
    </main>
  );
}
