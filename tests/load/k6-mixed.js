// Mixed load test: many parents (dashboard + Tuition API) and a few admins
// (dashboard + notification bell) at the same time. See tests/load/README.md.
//
// Run with Docker (no install needed):
//   docker run --rm -i -v "%cd%:/app" -w /app grafana/k6 run -e BASE=http://host.docker.internal/capstone-name/public tests/load/k6-mixed.js
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE || 'http://host.docker.internal/capstone-name/public';
const sessions = JSON.parse(open('../../storage/app/loadtest-cookies.json'));

export const options = {
  scenarios: {
    parents: {
      executor: 'ramping-vus', exec: 'parent',
      stages: [{ duration: '30s', target: 50 }, { duration: '1m', target: 50 }, { duration: '30s', target: 100 }, { duration: '1m', target: 100 }, { duration: '15s', target: 0 }],
    },
    admins: { executor: 'constant-vus', exec: 'admin', vus: 5, duration: '3m' },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],     // under 1% errors
    http_req_duration: ['p(95)<800'],   // 95% of requests under 0.8 s
  },
};

export function parent() {
  const s = sessions.parents[(__VU - 1) % sessions.parents.length];
  const headers = { Cookie: s.cookie };
  check(http.get(`${BASE}/parent`, { headers }), { 'parent dashboard 200': (r) => r.status === 200 });
  sleep(1 + Math.random() * 2); // think time
  check(http.get(`${BASE}/tuition?enrollment_id=${s.enrollment_id}`, { headers: { ...headers, Accept: 'application/json' } }), { 'tuition 200': (r) => r.status === 200 });
  check(http.get(`${BASE}/tuition/history`, { headers: { ...headers, Accept: 'application/json' } }), { 'history 200': (r) => r.status === 200 });
  sleep(2 + Math.random() * 3);
}

export function admin() {
  const headers = { Cookie: sessions.admin };
  check(http.get(`${BASE}/admin`, { headers }), { 'admin dashboard 200': (r) => r.status === 200 });
  sleep(2);
  check(http.get(`${BASE}/admin/notifications`, { headers: { ...headers, Accept: 'application/json' } }), { 'bell 200': (r) => r.status === 200 });
  sleep(3);
}
