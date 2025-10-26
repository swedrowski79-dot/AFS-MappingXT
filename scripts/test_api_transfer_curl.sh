#!/bin/bash

# Test script for Data Transfer API
# This script demonstrates how to call the data transfer API endpoint

# Set your API key here or use environment variable
API_KEY="${DATA_TRANSFER_API_KEY:-test_key_12345}"

# Set the API endpoint URL
API_URL="${API_URL:-http://localhost:8080/api/data_transfer.php}"

echo "=== Data Transfer API Test ==="
echo ""
echo "API URL: $API_URL"
echo "Transfer Type: ${1:-all}"
echo ""

# Make the API call
response=$(curl -s -X POST \
  -H "X-API-Key: ${API_KEY}" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "transfer_type=${1:-all}" \
  "${API_URL}")

# Check if curl was successful
if [ $? -ne 0 ]; then
    echo "✗ Failed to connect to API endpoint"
    exit 1
fi

# Pretty print JSON response
echo "Response:"
echo "$response" | python3 -m json.tool 2>/dev/null || echo "$response"

# Check if response indicates success
if echo "$response" | grep -q '"ok"\s*:\s*true'; then
    echo ""
    echo "✓ Transfer successful"
    exit 0
else
    echo ""
    echo "✗ Transfer failed"
    exit 1
fi
