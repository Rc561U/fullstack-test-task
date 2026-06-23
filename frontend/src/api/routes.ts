const API_PREFIX = "/api/admin";

export const routes = {
  transactions: {
    list: `${API_PREFIX}/transactions`,
    refund: (id: number) => `${API_PREFIX}/transactions/${id}/refund`,
  },
} as const;
