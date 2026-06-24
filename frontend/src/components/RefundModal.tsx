import { useEffect, useState, type FormEvent } from "react";
import { requestRefund } from "../api/client";
import type { Transaction } from "../types";

interface RefundModalProps {
  transaction: Transaction | null;
  onClose: () => void;
  onSuccess: (transactionId: number, refundAmount: string) => void;
}

function getRefundableAmount(transaction: Transaction): string {
  const refundable = Math.max(
    0,
    parseFloat(transaction.amount) - parseFloat(transaction.refundedAmount),
  );
  return refundable.toFixed(2);
}

function normalizeAmount(value: string): string {
  const parsed = Number.parseFloat(value);
  if (Number.isNaN(parsed)) {
    return "0.00";
  }
  return parsed.toFixed(2);
}

export function RefundModal({ transaction, onClose, onSuccess }: RefundModalProps) {
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!transaction) {
      return;
    }

    setAmount(getRefundableAmount(transaction));
    setReason("");
    setError(null);
    setSubmitting(false);
  }, [transaction]);

  if (!transaction) {
    return null;
  }

  const refundableAmount = getRefundableAmount(transaction);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!transaction) {
      return;
    }

    const currentTransaction = transaction;
    setError(null);

    const normalizedAmount = normalizeAmount(amount);
    if (parseFloat(normalizedAmount) <= 0) {
      setError("Сумма возврата должна быть больше нуля.");
      return;
    }

    if (parseFloat(normalizedAmount) > parseFloat(refundableAmount)) {
      setError(`Сумма не может превышать доступный остаток ${refundableAmount}.`);
      return;
    }

    if (reason.trim().length < 3) {
      setError("Причина должна содержать минимум 3 символа.");
      return;
    }

    setSubmitting(true);

    try {
      await requestRefund(currentTransaction.id, normalizedAmount, reason.trim());
      onSuccess(currentTransaction.id, normalizedAmount);
      onClose();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Не удалось выполнить возврат.");
    } finally {
      setSubmitting(false);
    }
  }

  function handleBackdropMouseDown(event: React.MouseEvent<HTMLDivElement>) {
    if (event.target === event.currentTarget) {
      onClose();
    }
  }

  return (
    <div className="refund-modal-backdrop" onMouseDown={handleBackdropMouseDown}>
      <div
        className="refund-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="refund-modal-title"
      >
        <header className="refund-modal-header">
          <h2 id="refund-modal-title">Возврат по транзакции #{transaction.id}</h2>
          <button
            type="button"
            className="refund-modal-close"
            onClick={onClose}
            aria-label="Закрыть"
          >
            ×
          </button>
        </header>

        <p className="refund-modal-meta">
          {transaction.merchantName} · {transaction.amount} {transaction.currency} · уже
          возвращено {transaction.refundedAmount}
        </p>

        <form className="refund-modal-form" onSubmit={handleSubmit}>
          <label className="refund-field">
            <span>Сумма возврата</span>
            <input
              type="text"
              inputMode="decimal"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              placeholder="10.00"
              disabled={submitting}
              required
            />
            <small>Доступно к возврату: {refundableAmount}</small>
          </label>

          <label className="refund-field">
            <span>Причина</span>
            <textarea
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder="Опишите причину возврата"
              rows={3}
              disabled={submitting}
              required
            />
          </label>

          {error && <p className="refund-modal-error">{error}</p>}

          <div className="refund-modal-actions">
            <button type="button" className="refund-secondary-btn" onClick={onClose} disabled={submitting}>
              Отмена
            </button>
            <button type="submit" className="refund-primary-btn" disabled={submitting}>
              {submitting ? "Отправка..." : "Подтвердить возврат"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
