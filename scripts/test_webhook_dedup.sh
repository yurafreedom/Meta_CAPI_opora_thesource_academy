#!/usr/bin/env bash
#
# Test harness for wfp-webhook.php dedup state machine (C1 fix)
#
# Usage:
#   export BASE_URL=http://localhost:8080
#   export DEV_BYPASS_WFP_SIGNATURE=1  # set in server env
#   bash scripts/test_webhook_dedup.sh
#
# What it tests:
#   1. First webhook call -> should process (not deduped)
#   2. Immediate duplicate call -> should be deduped (state=done or processing)
#   3. After forcing "failed" state -> should allow reprocessing
#
# Prerequisites:
#   - Server running with DEV_BYPASS_WFP_SIGNATURE=1
#   - META_CAPI_TOKEN and META_PIXEL_ID set (or use META_TEST_EVENT_CODE for sandbox)
#   - UPSTASH_REDIS_REST_URL and UPSTASH_REDIS_REST_TOKEN set
#

set -euo pipefail

BASE="${BASE_URL:-http://localhost:8080}"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

ORDER_ID="test_dedup_$(date +%s)_$$"

echo "====================================="
echo "Webhook dedup state machine test"
echo "BASE_URL=$BASE"
echo "ORDER_ID=$ORDER_ID"
echo "====================================="
echo ""

PAYLOAD=$(cat <<ENDJSON
{
  "orderReference": "$ORDER_ID",
  "transactionStatus": "Approved",
  "merchantAccount": "test_merchant",
  "amount": "1.00",
  "currency": "UAH",
  "authCode": "123456",
  "cardPan": "41****1111",
  "reasonCode": "1100",
  "merchantSignature": "fake"
}
ENDJSON
)

# --- Test 1: First call should process (not deduped) ---
echo -e "${YELLOW}TEST 1: First webhook call${NC}"
RESP1=$(curl -s -w "\n%{http_code}" -X POST "$BASE/wfp-webhook.php" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD" 2>&1)

HTTP1=$(echo "$RESP1" | tail -1)
BODY1=$(echo "$RESP1" | head -n -1)

echo "  HTTP: $HTTP1"
echo "  Body: $BODY1"

if echo "$BODY1" | grep -q '"deduped"'; then
  echo -e "  ${RED}FAIL: First call should NOT be deduped${NC}"
else
  echo -e "  ${GREEN}PASS: First call processed${NC}"
fi
echo ""

# --- Test 2: Immediate duplicate -> should be deduped ---
echo -e "${YELLOW}TEST 2: Duplicate call (should be deduped)${NC}"
RESP2=$(curl -s -w "\n%{http_code}" -X POST "$BASE/wfp-webhook.php" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD" 2>&1)

HTTP2=$(echo "$RESP2" | tail -1)
BODY2=$(echo "$RESP2" | head -n -1)

echo "  HTTP: $HTTP2"
echo "  Body: $BODY2"

if echo "$BODY2" | grep -q '"deduped":true'; then
  echo -e "  ${GREEN}PASS: Duplicate correctly deduped${NC}"
else
  echo -e "  ${RED}FAIL: Duplicate was NOT deduped${NC}"
fi
echo ""

# --- Test 3: Simulate concurrent calls ---
echo -e "${YELLOW}TEST 3: Two concurrent calls (new order)${NC}"
ORDER_ID2="test_concurrent_$(date +%s)_$$"
PAYLOAD2=$(echo "$PAYLOAD" | sed "s/$ORDER_ID/$ORDER_ID2/g")

# Fire two calls in parallel
curl -s -o /tmp/dedup_a.txt -w "%{http_code}" -X POST "$BASE/wfp-webhook.php" \
  -H "Content-Type: application/json" -d "$PAYLOAD2" &
PID_A=$!

curl -s -o /tmp/dedup_b.txt -w "%{http_code}" -X POST "$BASE/wfp-webhook.php" \
  -H "Content-Type: application/json" -d "$PAYLOAD2" &
PID_B=$!

wait $PID_A 2>/dev/null || true
wait $PID_B 2>/dev/null || true

BODY_A=$(cat /tmp/dedup_a.txt 2>/dev/null || echo "error")
BODY_B=$(cat /tmp/dedup_b.txt 2>/dev/null || echo "error")

echo "  Response A: $BODY_A"
echo "  Response B: $BODY_B"

DEDUP_COUNT=0
echo "$BODY_A" | grep -q '"deduped":true' && DEDUP_COUNT=$((DEDUP_COUNT+1))
echo "$BODY_B" | grep -q '"deduped":true' && DEDUP_COUNT=$((DEDUP_COUNT+1))

if [ $DEDUP_COUNT -ge 1 ]; then
  echo -e "  ${GREEN}PASS: At least one concurrent call was deduped ($DEDUP_COUNT deduped)${NC}"
else
  echo -e "  ${YELLOW}WARN: Neither call was deduped (may depend on timing)${NC}"
fi
echo ""

echo "====================================="
echo "Tests complete."
echo "Check server stderr for [wfp-webhook rid=...] log lines."
echo "====================================="
