// Source: anonymized production Laravel project
// server-блок vite.config.js для работы в Docker-контейнере.
// Принцип: внутри контейнера слушаем 0.0.0.0, но HMR-сокет браузер открывает
// с хоста — поэтому hmr.host = localhost. CORS — строго origin приложения.
import { defineConfig, loadEnv } from "vite";

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), "");
  const vitePort = Number(env.VITE_PORT) || 5173;
  const appOrigin = env.APP_URL || "http://localhost:8080";

  return {
    // Docker: 0.0.0.0 внутри контейнера; HMR/hot — localhost; CORS — origin приложения (APP_URL).
    server: {
      host: "0.0.0.0",
      port: vitePort,
      strictPort: true, // не уезжать молча на соседний порт — проброс в compose жёсткий
      hmr: {
        host: "localhost",
        port: vitePort,
      },
      cors: {
        origin: appOrigin,
        credentials: true,
      },
    },
    // ...plugins (laravel-vite-plugin и т.д.) — вне рамок этого сниппета
  };
});
