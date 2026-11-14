FROM node:22-bookworm-slim
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --ignore-scripts
COPY frontend .
RUN npm run lint && npm test -- --maxWorkers=2 && npm run build && npm audit
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "5174", "--strictPort"]
