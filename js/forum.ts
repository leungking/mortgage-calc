import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import m from 'mithril';

// Pure calculation helpers (standard formulas, all client-side).
function num(v: any): number {
  const n = parseFloat(v);
  return isNaN(n) ? 0 : n;
}

function fmt(n: number): string {
  return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Equal installments (等额本息): fixed monthly payment.
function equalInstallment(principal: number, annualRate: number, months: number): any {
  if (months <= 0 || principal <= 0) return { monthly: 0, interest: 0, total: 0 };
  const r = annualRate / 100 / 12;
  if (r === 0) {
    const monthly = principal / months;
    return { monthly, interest: 0, total: principal };
  }
  const pow = Math.pow(1 + r, months);
  const monthly = (principal * r * pow) / (pow - 1);
  const total = monthly * months;
  return { monthly, interest: total - principal, total };
}

// Equal principal (等额本金): fixed principal slice, declining interest.
function equalPrincipal(principal: number, annualRate: number, months: number): any {
  if (months <= 0 || principal <= 0) return { first: 0, decline: 0, interest: 0, total: 0 };
  const r = annualRate / 100 / 12;
  const slice = principal / months;
  const first = slice + principal * r;
  const decline = slice * r;
  const interest = (principal * r * (months + 1)) / 2;
  return { first, decline, interest, total: principal + interest };
}

// NOTE: Flarum 2.x DefaultResolver only accepts Component subclasses (or lazy
// imports) as route components. A plain object component is treated as an
// async import, called as a function, and the resulting error silently
// redirects to the homepage. Hence this page is a Component subclass.
class MortgagePage extends Component {
  mode: string = 'single';
  method: string = 'equal';
  amount: string = '1000000';
  rate: string = '3.05';
  months: string = '240';
  comAmount: string = '700000';
  comRate: string = '3.05';
  fundAmount: string = '300000';
  fundRate: string = '2.6';

  calc(): any {
    const months = Math.floor(num(this.months));
    const parts =
      this.mode === 'combo'
        ? [
            { p: num(this.comAmount), r: num(this.comRate) },
            { p: num(this.fundAmount), r: num(this.fundRate) },
          ]
        : [{ p: num(this.amount), r: num(this.rate) }];

    if (this.method === 'equal') {
      const sums = parts.reduce(
        (acc: any, part: any) => {
          const res = equalInstallment(part.p, part.r, months);
          acc.monthly += res.monthly;
          acc.interest += res.interest;
          acc.total += res.total;
          return acc;
        },
        { monthly: 0, interest: 0, total: 0 }
      );
      return { kind: 'equal', ...sums };
    }

    const sums = parts.reduce(
      (acc: any, part: any) => {
        const res = equalPrincipal(part.p, part.r, months);
        acc.first += res.first;
        acc.decline += res.decline;
        acc.interest += res.interest;
        acc.total += res.total;
        return acc;
      },
      { first: 0, decline: 0, interest: 0, total: 0 }
    );
    return { kind: 'principal', ...sums };
  }

  field(label: string, key: string, suffix?: string) {
    const self: any = this;
    return m('div.Form-group', [
      m('label.label', label),
      m('div', { style: 'display:flex; gap:8px; align-items:center;' }, [
        m('input.FormControl', {
          type: 'number',
          min: 0,
          value: self[key],
          oninput: (e: any) => {
            self[key] = e.target.value;
          },
        }),
        suffix ? m('span', suffix) : null,
      ]),
    ]);
  }

  view() {
    const res = this.calc();

    return m('div.HooeyMortgage', { style: 'max-width:640px; margin:0 auto; padding:16px;' }, [
      m('h2', '房贷计算器'),
      m('p', '纯前端计算，结果仅供参考。'),

      m('div.Form-group', [
        m('label.label', '贷款方式'),
        m('div', [
          m(
            'button.Button',
            {
              type: 'button',
              className: this.mode === 'single' ? 'Button--primary' : '',
              style: 'margin-right:8px;',
              onclick: () => {
                this.mode = 'single';
              },
            },
            '单笔贷款'
          ),
          m(
            'button.Button',
            {
              type: 'button',
              className: this.mode === 'combo' ? 'Button--primary' : '',
              onclick: () => {
                this.mode = 'combo';
              },
            },
            '组合贷'
          ),
        ]),
      ]),

      m('div.Form-group', [
        m('label.label', '还款方式'),
        m('div', [
          m(
            'button.Button',
            {
              type: 'button',
              className: this.method === 'equal' ? 'Button--primary' : '',
              style: 'margin-right:8px;',
              onclick: () => {
                this.method = 'equal';
              },
            },
            '等额本息'
          ),
          m(
            'button.Button',
            {
              type: 'button',
              className: this.method === 'principal' ? 'Button--primary' : '',
              onclick: () => {
                this.method = 'principal';
              },
            },
            '等额本金'
          ),
        ]),
      ]),

      this.mode === 'single'
        ? [this.field('贷款总额', 'amount', '元'), this.field('年利率', 'rate', '%'), this.field('期限', 'months', '月')]
        : [
            this.field('商贷金额', 'comAmount', '元'),
            this.field('商贷年利率', 'comRate', '%'),
            this.field('公积金金额', 'fundAmount', '元'),
            this.field('公积金年利率', 'fundRate', '%'),
            this.field('期限', 'months', '月'),
          ],

      m('hr'),
      m('h3', '计算结果'),
      res.kind === 'equal'
        ? m('ul', [
            m('li', `每月月供：¥${fmt(res.monthly)}`),
            m('li', `总利息：¥${fmt(res.interest)}`),
            m('li', `还款总额：¥${fmt(res.total)}`),
          ])
        : m('ul', [
            m('li', `首月月供：¥${fmt(res.first)}`),
            m('li', `每月递减：¥${fmt(res.decline)}`),
            m('li', `总利息：¥${fmt(res.interest)}`),
            m('li', `还款总额：¥${fmt(res.total)}`),
          ]),
    ]);
  }
}

(app as any).initializers.add('leungking-mortgage-calc', () => {
  (app as any).routes['hooey.mortgage'] = {
    path: '/mortgage',
    component: MortgagePage,
  };
});
