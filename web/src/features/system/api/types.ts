export interface HealthCheck {
  ok: boolean;
  error?: string;
}

export interface Health {
  status: 'ok' | 'degraded';
  app: string;
  environment: string;
  version: string;
  time: string;
  checks: Record<string, HealthCheck>;
}
