# API and Claude (MCP)

Nugget has a read-only JSON API and an MCP server over the same figures, so that a program, or an assistant such as Claude, can read your holdings and your tax report. Neither can change anything, and neither ever returns an exchange key or secret.

## 1. Make a token

Sign in, open **Profile**, and under **API tokens** give a token a name (what it is for, such as "Claude") and press **Make a token**. Copy it from the box that appears: it is shown once. Revoke it from the same list and it stops working at once.

## 2. Use the API

Every endpoint is a GET under `/api/v1` and takes the token as a bearer token:

```bash
curl -H "Authorization: Bearer YOUR-TOKEN" -H "Accept: application/json" https://nugget.example.com/api/v1/holdings
```

| Endpoint | Returns |
|---|---|
| `/api/v1/me` | whose token it is, and their timezone |
| `/api/v1/holdings` | each asset held, with units, cost base, price, value and unrealised gain, and the portfolio totals |
| `/api/v1/assets/{id}/parcels` | an asset's open parcels |
| `/api/v1/transactions` | the ledger, paged. Filters: `asset`, `type`, `source`, `from`, `to`, `estimated`; paging: `page`, `per_page` |
| `/api/v1/tax/years` | the financial years: every open one, and the closed ones with disposals |
| `/api/v1/tax/{year}` | a year's capital gains report. The year is the one it ends in: `2025` is 2024–25 |
| `/api/v1/issues` | balance mismatches, and open parcels with no cost base |

Amounts are decimal strings, never JSON numbers. Dates are ISO 8601 in your own timezone. A token may make 60 requests a minute.

## 3. Connect Claude

Register the MCP server with Claude Code, with the same token:

```bash
claude mcp add --transport http nugget https://nugget.example.com/mcp --header "Authorization: Bearer YOUR-TOKEN"
```

Use your own address in place of `nugget.example.com` (in development, `http://localhost:8778/mcp`). Add `--scope user` to have it in every project. `claude mcp list` shows whether it connected, and `claude mcp remove nugget` takes it away.

Claude then has six tools, one for each endpoint above: `holdings`, `parcels`, `transactions`, `tax_years`, `tax_report` and `issues`. Ask it something like "what is my net capital gain for 2024–25, and does any of it rest on estimated costs?".

The tax report is a working paper, not tax advice. It takes in crypto only unless you choose other asset classes on the tax report page.
