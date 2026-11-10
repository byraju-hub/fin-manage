"""M2 이후 시험 공용: 시험 계정·역할을 만들고 정리한다."""
import os, sys, subprocess
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from auth_flow_lib import Client, sql, PW  # noqa

fails = 0
def check(name, cond, extra=''):
    global fails
    print(('PASS ' if cond else 'FAIL ') + name + (' ' + str(extra)[:300] if not cond else ''))
    if not cond: fails += 1
def code(j): return (j.get('error') or {}).get('code')
def finish():
    print('\n실패 %d건' % fails); sys.exit(1 if fails else 0)

def wipe():
    """시험이 만든 모든 것을 지운다 (시험 계정 t_*, 시험 연도, 시험 이름 자료)."""
    Y = "(2097,2098,2099)"
    sql(f"delete from fm_voucher_items where voucher_id in (select id from fm_vouchers where fiscal_year in {Y})")
    sql(f"delete from fm_vouchers where fiscal_year in {Y}")
    sql(f"delete from fm_transactions where fiscal_year in {Y}")
    sql(f"delete from fm_budget_changes where fiscal_year in {Y}")
    sql(f"delete from fm_income_budgets where fiscal_year in {Y}")
    sql(f"delete from fm_offerings where batch_id in (select id from fm_offering_batches where fiscal_year in {Y})")
    sql(f"delete from fm_offering_batches where fiscal_year in {Y}")
    sql("delete from fm_receipts where tax_year in (2097,2098) or donor_id in (select id from fm_donors where name like '시험%')")
    sql("delete from fm_voucher_items where voucher_id in (select id from fm_vouchers where title like '시험%')")
    sql("delete from fm_vouchers where title like '시험%'")
    sql("delete from fm_transactions where summary like '시험%' or created_by in (select id from fm_access where login_id like 't\\_%') or wallet_id in (select id from fm_wallets where name like '시험%')")
    sql("delete from fm_offerings where created_by in (select id from fm_access where login_id like 't\\_%')")
    sql("delete from fm_offering_batches where created_by in (select id from fm_access where login_id like 't\\_%')")
    sql("update fm_donors set receipt_to_donor_id=null")
    sql("delete from fm_donors where id not in (select donor_id from fm_offerings where donor_id is not null)")
    sql("delete from fm_budget_changes where reason like '시험%'")
    sql("delete from fm_budget_items where name like '시험%'")
    sql("delete from fm_delegations where from_name like '시험%'")
    sql("delete from fm_closings where period in ('2097-01','2098-01','2026-11') and note like '시험%'")
    sql("delete from fm_access where login_id like 't\\_%'")
    sql("delete from cm_users where login_id like 't\\_%'")
    sql("delete from fm_units where fiscal_year in (2097,2098,2099) or name like '시험%'")
    sql("delete from fm_fiscal_years where year in (2097,2098,2099)")
    sql("delete from fm_income_accounts where name like '시험%'")
    sql("delete from fm_wallets where name like '시험%'")
    sql("delete from fm_funds where name like '시험%'")
    sql("delete from fm_change_log where by_name like '시험%'")

def make_users(spec):
    """spec: {login: (이름, [역할], is_admin, can_view_donors, person_index)} → {login: Client}"""
    people = sql("select id from cm_people where purged_on is null order by seq limit 12").split('\n')
    h = subprocess.run([r'C:\xampp\php\php.exe', '-r', f"echo password_hash('{PW}', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
    out = {}
    for login, (name, roles, admin, view, pi) in spec.items():
        sql(f"insert into cm_users (login_id,password_hash,name,person_id,status,grade) values ('{login}','{h}','{name}','{people[pi]}','사용','C')")
        sql(f"insert into fm_access (cm_user_id,login_id,name,person_id,status,is_admin,can_view_donors) select id,login_id,name,person_id,'사용',{admin},{view} from cm_users where login_id='{login}'")
        for r in roles:
            sql(f"insert into fm_access_roles select id,'{r}' from fm_access where login_id='{login}'")
        c = Client(); c.login(login); out[login] = c
    return out, people
