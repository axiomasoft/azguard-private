// Source: anonymized production Laravel project
// server-block vite.config.js to work in Docker-container.
// Principle: inside the container we listen to 0.0.0.0, but HMR-socket browser opens
// from the host - therefore hmr.host = localhost. CORS — strictly origin applications.
import { defineConfig, loadEnv } from "vite";

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), "");
  const vitePort = Number(env.VITE_PORT) || 5173;
  const appOrigin = env.APP_URL || "http://localhost:8080";

  return {
    // Docker: 0.0.0.0 inside the container; HMR/hot — localhost; CORS — origin applications (APP_URL).
    server: {
      host: "0.0.0.0",
      port: vitePort,
      strictPort: true, // do not leave silently to a neighboring port - forwarding to compose hard
      hmr: {
        host: "localhost",
        port: vitePort,
      },
      cors: {
        origin: appOrigin,
        credentials: true,
      },
    },
    // ...plugins (laravel-vite-plugin etc.) — is outside the scope of this snippet
  };
});
