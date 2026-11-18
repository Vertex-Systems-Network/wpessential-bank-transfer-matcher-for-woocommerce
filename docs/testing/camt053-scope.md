# CAMT.053 adapter scope

The internal parser accepts `camt.053.001.02` and `.08` XML documents with exactly one statement and one receiving account. It requires an account identifier ending in the selected bank account's safe suffix and an exact account and entry currency. Each entry must represent one transaction with a booking date, signed credit/debit direction and exact decimal amount. Optional `AcctSvcrRef` is retained as a bank ID; `EndToEndId` is a payment reference. `NOTPROVIDED` is treated as absent.

The parser rejects DTDs, entity declarations, unsupported namespaces, multiple statements, entries with multiple `TxDtls`, missing account details, currency mismatches and invalid dates or precision. It accepts at most 20 MiB and 10,000 entries. Parsing only creates canonical values in memory; it does not import rows or approve payments.

Synthetic fixtures cover both named namespaces, credit/debit and security failures. This is **not a release compatibility claim**. CAMT.053 upload and background ingestion are not connected to this adapter yet. Before advertising support, test anonymized examples from supported banks, verify variants of transaction detail and reversal structures, and complete the import UI, private storage and duplicate handling gates.
