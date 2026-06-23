import type { RefundResponse, Transaction } from "../types";
import { extractApiError } from "./errorHandler";
import { http } from "./http";
import { routes } from "./routes";

export async function fetchTransactions(): Promise<Transaction[]> {
  try {
    const { data } = await http.get<Transaction[]>(routes.transactions.list);
    return data;
  } catch (err) {
    throw new Error(extractApiError(err, "Failed to load transactions"));
  }
}

export async function requestRefund(
  id: number,
  amount: string,
  reason: string,
): Promise<RefundResponse> {
  try {
    const { data } = await http.post<RefundResponse>(
      routes.transactions.refund(id),
      { amount, reason },
      { headers: { "Idempotency-Key": crypto.randomUUID() } },
    );
    return data;
  } catch (err) {
    throw new Error(extractApiError(err, "Refund request failed"));
  }
}
