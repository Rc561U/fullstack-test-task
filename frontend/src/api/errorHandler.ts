import axios from "axios";

const ERROR_MESSAGE_KEYS = ["error", "detail", "title"] as const;

export function extractApiError(err: unknown, fallback: string): string {
  if (axios.isAxiosError(err) && err.response) {
    const data = err.response.data as Record<string, unknown> | undefined;
    if (data && typeof data === "object") {
      for (const key of ERROR_MESSAGE_KEYS) {
        if (typeof data[key] === "string") return data[key];
      }
    }
    return `${fallback} (${err.response.status})`;
  }

  if (err instanceof Error) {
    return err.message;
  }

  return fallback;
}
