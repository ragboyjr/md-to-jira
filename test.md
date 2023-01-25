# RSS Transfer Support
{workCategorization="Product Development" epicName="RSS Ether Integration" jiraIssueKey="B20-4382"}

# Stories

## Handle Large RSS Orders
{storyPoints="2" jiraIssueKey="B20-4384"}

![Untitled](https://s3-us-west-2.amazonaws.com/secure.notion-static.com/3f993ef6-a015-45ad-8b76-8802fe4e6ffa/Untitled.png)

AC:

- Each RSS Order picking queue card will need to handle scrolling & show how many items are scanned vs not when number of items is greater than 5.
- Multi Item Order items need to be sorted by the following logic:
    - If not scanned, those go on top, if scanned, those go on bottom
    - then Sort by location to ensure that the items in the order card are shown in location order.

## Shuttled Transfer Lane
{storyPoints="3" jiraIssueKey="B20-4385"}

- By default, all transfers from retail locations are shuttled and not shipped through a carrier. When this happens, the pick/pack flow is a bit different.
    - Picking is exactly the same
    - However, there is no Pack flow, all items would be immediately marked as packed and ready to be shipped, all items ship at once. No boxes/labels are created
- Lane will be used to determine the flow. Maybe should call the lane something like `ShuttledTransfer` or something like that.

AC:

If rss order lane is Shuttled Transfer

- After all items picked, all items need to be marked as scanned for packing
- Ship button needs to be present once order hits the pack queue
- Order can be shipped without creating boxes

Technical Note:

- Implement this in the naive way (basic if statements in domain methods)
- Add specs + ship
- then refactor to a more expressive way to allow order to have different lane handlers or something of the like to help move order state according to the lane

## Transfer Creation Feature Flag
{storyPoints="2" jiraIssueKey="B20-4386"}

- When creating a transfer, add another checkbox with value like `RSS Fulfillment` by default this will be unchecked.
- This flag data needs to be stored on the transfer model itself

## RSS Origin Transfers Should Skip Picking
{storyPoints="5" jiraIssueKey="B20-4387"}

Currently, if it’s a retail origin transfer, the transfer will try and go into the picking state.

AC:

- If RSS origin transfer AND transfer is set for RSS Fulfillment
    - Transfer should follow the same exact flow as standard third party transfers
    - Specifically, transfer flow should look like `pending -> requested_ship -> (might be some x3 ship statuses in here according to normal flow) shipped -> closed`
    - The focus of this task is around pending → requested_ship instead OF `pending → ready_to_pick` . These transfers should NEVER go into `ready_to_pick`

## Export Transfers to RSS
{storyPoints="3" jiraIssueKey="B20-4388"}

AC:

- Any transfer that is retail + set for RSS fulfillment in `requested_ship` state should be included in the list of transfers to export
- RSS origin transfers, should be sent to RSS (for now, we can simply just do nothing in the rss handler) this way, RSS transfers will continually be picked up until we write the code to actually export and mark rss transfer as `mark_as_requested_3pl_ship_by_id`
    - DO NOT MARK RSS TRANSFERS AS `mark_as_requested_3pl_ship_by_id` IN THIS TICKET, WE’LL DO THAT IN A LATER TICKET

Technical Notes:

- ExportThirdPartyTransfers is going to be the main place to update

## Create RSS Order from Transfer
{storyPoints="3" jiraIssueKey="B20-4389"}

In the export third party transfers rss handler:

AC:

- RSS Transfers are marked as `mark_as_requested_3pl_ship_by_id` after create rss order message is sent.
- RSS CreateOrder command is built up from transfer + transfer items
    - if ship to destination, then use the sg fulfillment lane
    - if not ship to destination, then use the shuttled transfer lane
    - transfer items map to rss order items
    - rss order address is transfer destination warehouse address
    - **sales_channel_order_id is nil**
    - delivery_method_code is unclear, maybe it’s GROUND or something?
        - delivery_method_code should be hardcoded to `DeliveryMethod::FEDEX_GROUND_SHIP_METHOD`
    - source_type should be transfer

## Bubble Up Shipped RSS Orders to Transfers
{storyPoints="3" jiraIssueKey="B20-4390"}

New worker to poll and listen for shipped rss orders to move transfers into next state.

AC:

- For all rss transfers in requested_3pl_ship state
    - find shipped rss order
    - mark transfer items as picked
    - `transfer.requested_ship? ? transfer.request_x3_ship_for_3pl : transfer.retry_shipment_creation_for_3pl` mark transfer to request x3 ship for 3pl

Notes:

- View process_third_party_shipment#process_transfer for more context on how we do this for other third party transfers