/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL: string
  readonly VITE_APP_NAME: string
  readonly VITE_APP_ENV: string
  /** Portal authentication_jump key. Defaults to the hostname's product. */
  readonly VITE_PRODUCT_KEY: string
  /** Overrides the login portal origin. For local development only. */
  readonly VITE_PORTAL_LOGIN_URL: string
  /** GA4 measurement ID for this product. Analytics is disabled when unset. */
  readonly VITE_GA4_SAAS_EMAIL_MEASUREMENT_ID?: string
  /** Generic GA4 measurement ID fallback, checked when the product-specific one is unset. */
  readonly VITE_GA4_MEASUREMENT_ID?: string
  /** `business` | `personal` — which experience localhost renders. Development only. */
  readonly VITE_DEV_FRONTEND_MODE?: string
  /** Per-mode API overrides, for pointing one destination at a different backend. */
  readonly VITE_BUSINESS_API_BASE_URL?: string
  readonly VITE_PERSONAL_API_BASE_URL?: string
  /** Reviewed staging hosts, comma separated. Production hosts are in the code. */
  readonly VITE_EXTRA_BUSINESS_HOSTS?: string
  readonly VITE_EXTRA_PERSONAL_HOSTS?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
