# How the tax report is calculated

The tax report is a capital gains working paper for one Australian financial year. It is for you
and your accountant to check. It is not tax advice, and [its limits](#limits) are listed below.

The page, the PDF, the CSV and the API all read the same calculation, so their figures cannot
differ.

## The year

A financial year runs from 1 July to 30 June and is named by the year it ends in: 2025 is 2024–25.
A sale belongs to the year its date falls in, by the timezone on your profile.

An admin sets the first year Nugget reports on (**Settings**, 2024–25 unless changed). Earlier
years are closed: they have no report, but their sales still use up parcels, so later years start
from what was really left.

## Parcels

Every time units come in, Nugget opens a parcel: the units, the date they were acquired and their
cost base in AUD.

| Transaction | Parcel's cost base |
|---|---|
| Buy | the total paid, fee included |
| Found (prospected gold, for one) | the total entered, usually $0 |
| Reward (staking, an airdrop) | the total entered: its value in AUD when it was received |
| Adjust up | the total entered |

Units going out use parcels up:

- **A sale** uses up parcels and makes one disposal line for each parcel it touches.
- **A transfer out** and **an adjust down** use up the oldest parcels and make no disposal: neither
  is a gain or a loss.
- **A transfer in** brings back what a transfer out removed, oldest first, with the cost base and
  the acquired date it left with. Moving a coin from an exchange to a wallet does not restart its
  12 months. Transfers join your assets that have the same symbol and unit. Units that arrive with
  nothing waiting open a parcel at the transfer's own date and total.

## Which parcels a sale uses

An admin chooses the method on **Settings**:

- **First in, first out**, the default: the oldest parcel goes first.
- **Lowest gain first**: the parcel with the smallest taxable gain per unit goes first. A gain on a
  parcel held longer than 12 months counts for half, since the discount will halve it; a loss
  counts in full, so the biggest loss goes first of all.

You can also pick the parcels of any one sale by hand.

A sale remembers the method it was first matched by. Changing the setting later changes new sales
only, not the ones already matched.

## A disposal's gain

For each parcel a sale uses:

- **Proceeds**: the sale's total, which is the AUD received less the fee, shared between the
  parcels by units.
- **Cost base**: the share of the parcel's cost that the units sold carry.
- **Gain or loss**: proceeds less cost base.

The shares are cut from running totals, so the lines of a sale add up to the sale exactly, and the
pieces of a parcel add up to its cost exactly.

## Held longer than 12 months

A gain gets the discount when its parcel was held longer than 12 months, not counting the day it
was acquired or the day it was sold, by your timezone. A parcel acquired on 10 March is long held
when it is sold on or after 11 March the next year.

A loss is not split by how long it was held: the discount belongs to gains only.

## The summary

1. Add up the year's gains on parcels held 12 months or less, its gains on parcels held longer, and
   its losses.
2. Add the losses you carried forward from earlier years to this year's losses.
3. Take the losses off the short-held gains first, then off the long-held gains. That order gives
   the lowest net gain the rules allow. Losses come off gains in either section.
4. Halve what is left of the long-held gains: the 50% discount for an individual.
5. The **net capital gain** is what is left of both. It is never below zero.
6. Losses bigger than the gains are the **loss carried forward** to next year.

Nugget does not fill in the losses carried forward for you. The report page shows what the year
before leaves over; type it in, or the figure your lodged return had, and save it for the year.

## What the report takes in

- **Sections**: crypto, and one "other assets" section for metals and everything else.
- **Asset classes**: a year's report takes in crypto only until you choose otherwise on the report
  page. The choice is saved for that year. A class left out is out of the sections and the summary
  alike.
- **Estimated costs**: a parcel whose cost is a best guess (an imported balance with no purchase
  record, for one) is flagged, and every disposal that used one is marked "Estimated" and counted
  at the top of the report. Replace the estimates with the real costs before relying on the
  figures.

Both the page and the PDF say which classes were left out.

## Limits

- It is a working paper, not tax advice.
- **Capital gains only.** Staking rewards and airdrops open a parcel at the value you enter, but
  are not totalled as income.
- **Individuals only.** The discount is always 50%. A company, a trust or a super fund has
  different rules, which Nugget does not know.
- **Metals are treated like any other asset**, not as collectables or personal use assets.
- **AUD only.** Every cost and every sale is in AUD. A trade of one coin for another on a market
  that is not priced in AUD is passed over by the exchange sync and noted on the exchange's page.
  It is a disposal: enter it by hand as a sale and a buy at their AUD values.
- **An adjust down is not a loss.** Units that were lost or stolen come off the holding with no
  capital loss claimed.
- **Nothing is indexed, and no other exemption or concession is applied.**
- The figures are only as good as the ledger. A balance mismatch between an exchange and the
  ledger is listed under the bell on the top bar, and the API's `issues` endpoint lists those and
  the open parcels with no cost base.
