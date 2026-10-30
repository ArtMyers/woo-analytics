// Звіряє звіт плагіна (/wc-sqwa/v1/report) з незалежним розрахунком по WooCommerce REST API.
// Використання: npm run verify [-- --statuses=completed,processing,on-hold]
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
process.loadEnvFile(path.join(root, '.env'));

const { WP_URL, WC_KEY, WC_SECRET } = process.env;
if (!WP_URL || !WC_KEY || !WC_SECRET) {
  console.error('У .env потрібні WP_URL, WC_KEY, WC_SECRET');
  process.exit(1);
}

const statusArg = process.argv.find((a) => a.startsWith('--statuses='));
const statuses = (statusArg ? statusArg.split('=')[1] : 'completed,processing,on-hold').split(',');

async function api(route, params = {}) {
  const url = new URL(`${WP_URL.replace(/\/$/, '')}/wp-json/${route}`);
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
  url.searchParams.set('consumer_key', WC_KEY);
  url.searchParams.set('consumer_secret', WC_SECRET);
  const res = await fetch(url);
  const body = await res.json().catch(() => null);
  return { status: res.status, body, total: Number(res.headers.get('x-wp-totalpages') || 1) };
}

// --- Звіт плагіна
const report = await api('wc-sqwa/v1/report', { details: 'true', statuses: statuses.join(',') });
if (report.status !== 200) {
  console.error(`Звіт плагіна недоступний (HTTP ${report.status}):`, report.body?.message ?? report.body);
  console.error('Перевірте, що плагін встановлено й активовано.');
  process.exit(1);
}
const pluginItems = new Map(report.body.details.map((d) => [d.item_id, d]));

// --- Незалежний розрахунок
const orders = [];
for (let page = 1, pages = 1; page <= pages; page++) {
  const r = await api('wc/v3/orders', { status: statuses.join(','), per_page: 100, page });
  if (r.status !== 200) throw new Error(`orders HTTP ${r.status}: ${JSON.stringify(r.body)}`);
  orders.push(...r.body);
  pages = r.total;
}

const productCache = new Map();
async function product(id) {
  if (!id) return null;
  if (!productCache.has(id)) {
    const r = await api(`wc/v3/products/${id}`);
    productCache.set(id, r.status === 200 ? r.body : null);
  }
  return productCache.get(id);
}

function tierKeys(p) {
  if (!p) return [];
  const rules = p.tiered_pricing_type === 'percentage' ? p.tiered_pricing_percentage_rules : p.tiered_pricing_fixed_rules;
  return Object.keys(rules && typeof rules === 'object' ? rules : {})
    .map(Number)
    .filter((n) => n > 0)
    .sort((a, b) => a - b);
}

async function expectedType(li) {
  const saved = li.meta_data?.find((m) => m.key === '_sqwa_price_type')?.value;
  if (saved === 'wholesale' || saved === 'retail') return saved;

  const p = await product(li.variation_id || li.product_id);
  if (!p) return 'unknown';
  let keys = tierKeys(p);
  if (!keys.length && p.parent_id) keys = tierKeys(await product(p.parent_id));
  if (!keys.length || li.quantity <= 0) return 'retail';

  const unit = Number(li.subtotal) / li.quantity;
  const regular = Number(p.regular_price);
  return li.quantity >= keys[0] && regular > 0 && unit < regular - 0.0001 ? 'wholesale' : 'retail';
}

const sums = { plugin: { retail: 0, wholesale: 0, unknown: 0 }, expected: { retail: 0, wholesale: 0, unknown: 0 } };
const mismatches = [];
let checked = 0;

for (const order of orders) {
  for (const li of order.line_items) {
    const exp = await expectedType(li);
    const got = pluginItems.get(li.id);
    sums.expected[exp] += li.quantity;
    checked++;
    if (!got) {
      mismatches.push({ order: order.id, item: li.id, name: li.name, qty: li.quantity, expected: exp, plugin: '(немає у звіті)' });
      continue;
    }
    sums.plugin[got.type] += got.qty;
    if (got.type !== exp) {
      mismatches.push({ order: order.id, item: li.id, name: li.name, qty: li.quantity, expected: exp, plugin: `${got.type} (${got.source})` });
    }
  }
}

console.log(`Статуси: ${statuses.join(', ')} · замовлень: ${orders.length} · позицій: ${checked}`);
console.log('\nОдиниць товару (до повернень):');
console.table(sums);
console.log('Підсумки плагіна (з урахуванням повернень):');
console.table(
  Object.fromEntries(Object.entries(report.body.totals).map(([k, v]) => [k, { qty: v.qty, total: v.total, orders: v.orders }])),
);
console.log(`Частка опту: ${report.body.share}%`);

if (report.body.details.length !== checked) {
  console.warn(`\n⚠ Кількість позицій різниться: плагін ${report.body.details.length}, REST ${checked}`);
}
if (mismatches.length) {
  console.log(`\n✖ Розбіжностей: ${mismatches.length}`);
  console.table(mismatches);
  process.exit(2);
}
console.log('\n✔ Класифікація всіх позицій збігається');
