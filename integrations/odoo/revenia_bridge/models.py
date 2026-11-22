from decimal import ROUND_HALF_UP, Decimal
from uuid import UUID

from odoo import Command, api, fields, models, release
from odoo.exceptions import AccessError, UserError

_IMPORT_TOKEN = object()


def scaled(value, multiplier=100, exact=False):
    units = Decimal(str(value)) * multiplier
    rounded = units.quantize(Decimal(1), rounding=ROUND_HALF_UP)
    if exact and units != rounded:
        return str(units)
    return int(rounded)


class Company(models.Model):
    _inherit = "res.company"

    revenia_tenant = fields.Char(string="UUID de empresa en Revenia", copy=False)

    @api.constrains("revenia_tenant")
    def _check_tenant(self):
        for company in self.filtered("revenia_tenant"):
            try:
                valid = str(UUID(company.revenia_tenant)) == company.revenia_tenant
            except ValueError:
                valid = False
            if not valid:
                raise UserError("Se requiere un UUID canónico de empresa.")

    def write(self, vals):
        if "revenia_tenant" in vals and not self.env.user.has_group(
            "base.group_system"
        ):
            raise AccessError("Solo el administrador puede vincular la empresa.")
        return super().write(vals)

    @api.model_create_multi
    def create(self, vals_list):
        if any(
            v.get("revenia_tenant") for v in vals_list
        ) and not self.env.user.has_group("base.group_system"):
            raise AccessError("Solo el administrador puede vincular la empresa.")
        return super().create(vals_list)


class MoveLine(models.Model):
    _inherit = "account.move.line"

    revenia_line = fields.Char(copy=False, readonly=True)
    revenia_product = fields.Char(copy=False, readonly=True)
    revenia_unit = fields.Char(copy=False, readonly=True)
    revenia_tax_category = fields.Char(copy=False, readonly=True)


class Move(models.Model):
    _inherit = "account.move"

    revenia_key = fields.Char(string="Clave de origen", readonly=True, copy=False)
    revenia_simulated = fields.Boolean(
        string="Emisión simulada en Revenia", readonly=True, copy=False
    )
    revenia_type = fields.Char(string="Tipo de origen", readonly=True, copy=False)
    revenia_number = fields.Char(string="Número en Revenia", readonly=True, copy=False)

    revenia_credit_reason = fields.Char(copy=False, readonly=True)
    revenia_customer_key = fields.Char(copy=False, readonly=True)
    revenia_customer_name = fields.Char(copy=False, readonly=True)
    revenia_customer_number = fields.Char(copy=False, readonly=True)

    _sql_constraints = [  # noqa: RUF012 - Odoo model declaration.
        (
            "revenia_origin_unique",
            "unique(revenia_key)",
            "Ya existe este comprobante de Revenia.",
        ),
    ]

    @api.model_create_multi
    def create(self, vals_list):
        context = dict(self.env.context)
        authorized = context.pop("_revenia_import_token", None) is _IMPORT_TOKEN
        if not authorized and (
            context.get("default_revenia_key")
            or any(v.get("revenia_key") for v in vals_list)
        ):
            raise AccessError("La clave se asigna desde el conector de Revenia.")
        return super(Move, self.with_context(context)).create(vals_list)

    def write(self, vals):
        if vals.get("revenia_key") and any(
            move.revenia_key != vals["revenia_key"] for move in self
        ):
            raise UserError("La identidad de origen es permanente.")
        protected = {
            "revenia_key",
            "revenia_type",
            "revenia_simulated",
            "revenia_number",
            "company_id",
        }.intersection(vals)
        for move in self.filtered("revenia_key") if protected else ():
            for field in protected:
                previous = move.company_id.id if field == "company_id" else move[field]
                if vals[field] != previous:
                    raise UserError("La identidad de origen es permanente.")
        return super().write(vals)

    @api.ondelete(at_uninstall=False)
    def _keep_revenia_origin(self):
        if self.filtered("revenia_key"):
            raise UserError("Conserve la referencia de origen para conciliación.")

    @api.model
    def revenia_probe(self, tenant):
        company = self.env.company
        if (
            company not in self.env.user.company_ids
            or not tenant
            or company.revenia_tenant != tenant
        ):
            raise AccessError("REVENIA_DESTINATION")
        for model, operations in {
            "account.move": ["read", "create"],
            "account.move.line": ["read", "create"],
            "account.tax": ["read"],
            "account.journal": ["read"],
            "account.account": ["read"],
            "res.partner": ["read"],
            "res.currency": ["read"],
            "uom.uom": ["read"],
        }.items():
            for operation in operations:
                self.env[model].check_access_rights(operation)
        company.check_access("read")
        if not self.env["account.journal"].search(
            [("company_id", "=", company.id), ("type", "=", "sale")], limit=1
        ):
            raise UserError("REVENIA_MAPPING")
        if not self.env["account.account"].search(
            [
                ("company_ids", "in", [company.id]),
                ("deprecated", "=", False),
                ("account_type", "in", ["income", "income_other"]),
            ],
            limit=1,
        ):
            raise UserError("REVENIA_MAPPING")
        self.env.cr.execute("""
            SELECT 1 FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid
            WHERE c.conrelid='account_move'::regclass AND c.contype='u' AND c.convalidated
              AND a.attname='revenia_key' AND c.conkey=ARRAY[a.attnum]
        """)
        if not self.env.cr.fetchone():
            raise UserError("REVENIA_UNIQUENESS")
        # Only the database identity needs an elevated read. All business records use the RPC user.
        database = self.env["ir.config_parameter"].sudo().get_param("database.uuid")
        if not database:
            raise UserError("REVENIA_DESTINATION")
        return {
            "contract": 1,
            "version": release.version_info[0],
            "database_uuid": database,
            "company_id": company.id,
            "name": company.name,
            "tenant": tenant,
        }

    def _revenia_check_destination(self, tenant, database, key):
        identity = self.revenia_probe(tenant)
        if database != identity["database_uuid"] or key.split("/", 1)[0] != tenant:
            raise AccessError("REVENIA_DESTINATION")
        try:
            if str(UUID(key.split("/")[1])) != key.split("/", 1)[1]:
                raise ValueError()
        except (IndexError, ValueError):
            raise UserError("REVENIA_ORIGIN")

    @api.model
    def revenia_lookup(self, tenant, database, key):
        self._revenia_check_destination(tenant, database, key)
        move = self.search(
            [("company_id", "=", self.env.company.id), ("revenia_key", "=", key)],
            limit=1,
        )
        return move._revenia_receipt() if move else False

    def _revenia_receipt(self):
        self.ensure_one()
        lines = []
        for line in self.invoice_line_ids.sorted(lambda r: (r.sequence, r.id)):
            lines.append(
                {
                    "source_line": line.revenia_line,
                    "source_product": line.revenia_product,
                    "source_unit": line.revenia_unit,
                    "category": line.revenia_tax_category,
                    "number": line.sequence,
                    "description": line.name,
                    "quantity": scaled(line.quantity, 1000, exact=True),
                    "unit_price": scaled(line.price_unit, exact=True),
                    "unit": line.product_uom_id.id,
                    "tax": line.tax_ids.id if len(line.tax_ids) == 1 else 0,
                    "account": line.account_id.id,
                    "discount": line.discount,
                    "base": scaled(line.price_subtotal),
                    "total": scaled(line.price_total),
                }
            )
        return {
            "id": self.id,
            "state": self.state,
            "company": self.company_id.id,
            "customer_key": self.revenia_customer_key,
            "customer_name": self.revenia_customer_name or "",
            "customer_number": self.revenia_customer_number or "",
            "key": self.revenia_key,
            "type": self.revenia_type,
            "simulated": self.revenia_simulated,
            "number": self.revenia_number,
            "credit_reason": self.revenia_credit_reason or "",
            "move_type": self.move_type,
            "partner": self.partner_id.id,
            "currency": self.currency_id.id,
            "iso": self.currency_id.name,
            "journal": self.journal_id.id,
            "original": self.reversed_entry_id.id or 0,
            "date": str(self.invoice_date),
            "due": str(self.invoice_date_due),
            "lines": lines,
            "base": scaled(self.amount_untaxed),
            "tax": scaled(self.amount_tax),
            "total": scaled(self.amount_total),
        }

    @api.model
    def revenia_import(self, tenant, database, data):
        key = data["key"]
        existing = self.revenia_lookup(tenant, database, key)
        if existing:
            return existing
        company = self.env.company

        def mapped(model, record_id, domain):
            record = self.env[model].search([("id", "=", record_id)] + domain, limit=1)
            if not record:
                raise UserError("REVENIA_MAPPING")
            return record

        partner = mapped(
            "res.partner", data["partner"], [("company_id", "in", [False, company.id])]
        )
        currency = mapped(
            "res.currency",
            data["currency"],
            [("name", "=", data["iso"]), ("rounding", "=", 0.01)],
        )
        journal = mapped(
            "account.journal",
            data["journal"],
            [("type", "=", "sale"), ("company_id", "=", company.id)],
        )
        if journal.currency_id and journal.currency_id != currency:
            raise UserError("REVENIA_MAPPING")
        move_type = {"01": "out_invoice", "03": "out_invoice", "07": "out_refund"}[
            data["type"]
        ]
        original = 0
        if move_type == "out_refund":
            original = mapped(
                "account.move",
                data["original"],
                [
                    ("company_id", "=", company.id),
                    ("revenia_key", "=", data["original_key"]),
                    ("partner_id", "=", partner.id),
                    ("currency_id", "=", currency.id),
                    ("move_type", "=", "out_invoice"),
                ],
            ).id
        commands = []
        for line in data["lines"]:
            unit = mapped("uom.uom", line["unit"], [])
            account = mapped(
                "account.account",
                line["account"],
                [
                    ("company_ids", "in", [company.id]),
                    ("account_type", "in", ["income", "income_other"]),
                    ("deprecated", "=", False),
                ],
            )
            rate = {"10": 18, "20": 0, "30": 0}[line["category"]]
            tax = mapped(
                "account.tax",
                line["tax"],
                [
                    ("company_id", "=", company.id),
                    ("type_tax_use", "=", "sale"),
                    ("amount_type", "=", "percent"),
                    ("amount", "=", rate),
                ],
            )
            if tax.price_include:
                raise UserError("REVENIA_MAPPING")
            commands.append(
                Command.create(
                    {
                        "revenia_line": line["source_line"],
                        "revenia_product": line["source_product"],
                        "revenia_unit": line["source_unit"],
                        "revenia_tax_category": line["category"],
                        "sequence": line["number"],
                        "name": line["description"],
                        "quantity": line["quantity"] / 1000,
                        "product_uom_id": unit.id,
                        "price_unit": line["unit_price"] / 100,
                        "discount": 0,
                        "tax_ids": [Command.set(tax.ids)],
                        "account_id": account.id,
                    }
                )
            )
        move = self.with_context(
            _revenia_import_token=_IMPORT_TOKEN,
            tracking_disable=True,
            mail_create_nosubscribe=True,
        ).create(
            {
                "state": "draft",
                "move_type": move_type,
                "company_id": company.id,
                "journal_id": journal.id,
                "partner_id": partner.id,
                "currency_id": currency.id,
                "invoice_date": data["date"],
                "invoice_payment_term_id": False,
                "invoice_date_due": data["due"],
                "ref": data["number"],
                "invoice_origin": data["number"],
                "revenia_credit_reason": data["credit_reason"],
                "revenia_customer_key": data["customer_key"],
                "revenia_customer_name": data["customer_name"],
                "revenia_customer_number": data["customer_number"],
                "revenia_type": data["type"],
                "revenia_simulated": data["simulated"],
                "revenia_number": data["number"],
                "revenia_key": key,
                "reversed_entry_id": original or False,
                "invoice_line_ids": commands,
                "auto_post": "no",
            }
        )
        receipt = move._revenia_receipt()
        expected = {k: data[k] for k in receipt if k not in ("id", "state", "lines")}
        if (
            move.state != "draft"
            or any(receipt[k] != v for k, v in expected.items())
            or receipt["lines"] != data["lines"]
        ):
            # Exception rolls back the entire RPC transaction, including the draft and its lines.
            raise UserError("REVENIA_DIFFERENCE")
        return receipt
