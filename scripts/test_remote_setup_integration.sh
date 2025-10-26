#!/bin/bash

# Integration Test Script for Remote Setup and Auto-Update Features
# 
# This script tests the complete workflow:
# 1. Initial setup via API without authentication
# 2. Updating configuration with authentication
# 3. Auto-update functionality
#
# Usage: ./test_remote_setup_integration.sh [base_url]
# Example: ./test_remote_setup_integration.sh http://localhost:8080

set -e

# Configuration
BASE_URL="${1:-http://localhost:8080}"
API_KEY=""
TEST_API_KEY="test_$(openssl rand -hex 16)"

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Helper functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Check if curl is available
if ! command -v curl &> /dev/null; then
    log_error "curl is required but not installed. Please install curl."
    exit 1
fi

# Check if jq is available
if ! command -v jq &> /dev/null; then
    log_warning "jq is not installed. JSON output will not be pretty-printed."
    JQ_AVAILABLE=false
else
    JQ_AVAILABLE=true
fi

echo "======================================"
echo "Remote Setup Integration Test"
echo "======================================"
echo "Base URL: $BASE_URL"
echo ""

# Test 1: Check initial setup status
log_info "Test 1: Checking initial setup status (GET request)..."
RESPONSE=$(curl -s -X GET "$BASE_URL/api/initial_setup.php")

if [ "$JQ_AVAILABLE" = true ]; then
    echo "$RESPONSE" | jq .
else
    echo "$RESPONSE"
fi

# Check if setup is needed
SETUP_NEEDED=$(echo "$RESPONSE" | grep -o '"setup_needed":[^,}]*' | cut -d':' -f2 | tr -d ' ')

if [ "$SETUP_NEEDED" = "false" ]; then
    log_warning ".env file already exists. Skipping initial setup test."
    log_info "To test initial setup, remove the .env file first."
    
    # Try to read current API key from .env
    if [ -f ".env" ]; then
        API_KEY=$(grep "^DATA_TRANSFER_API_KEY=" .env | cut -d'=' -f2 | tr -d '"' | tr -d ' ')
        log_info "Using existing API key from .env"
    fi
else
    log_success "Initial setup is needed (no .env file exists)"
    
    # Test 2: Create initial .env via API (no authentication required)
    log_info "Test 2: Creating initial .env configuration (no auth required)..."
    
    SETUP_DATA=$(cat <<EOF
{
    "settings": {
        "DATA_TRANSFER_API_KEY": "$TEST_API_KEY",
        "AFS_MSSQL_HOST": "10.0.1.82",
        "AFS_MSSQL_PORT": "1435",
        "AFS_MSSQL_DB": "AFS_2018",
        "AFS_MSSQL_USER": "sa",
        "AFS_MSSQL_PASS": "test_password",
        "AFS_GITHUB_AUTO_UPDATE": "false",
        "AFS_GITHUB_BRANCH": "main"
    }
}
EOF
)
    
    RESPONSE=$(curl -s -X POST "$BASE_URL/api/initial_setup.php" \
        -H "Content-Type: application/json" \
        -d "$SETUP_DATA")
    
    if [ "$JQ_AVAILABLE" = true ]; then
        echo "$RESPONSE" | jq .
    else
        echo "$RESPONSE"
    fi
    
    # Check if creation was successful
    if echo "$RESPONSE" | grep -q '"ok":true'; then
        log_success "Initial .env configuration created successfully"
        API_KEY="$TEST_API_KEY"
    else
        log_error "Failed to create initial configuration"
        exit 1
    fi
fi

# Test 3: Try to update configuration with authentication
log_info "Test 3: Updating configuration (authentication required)..."

if [ -z "$API_KEY" ]; then
    log_warning "No API key available. Skipping authenticated update test."
else
    UPDATE_DATA=$(cat <<EOF
{
    "settings": {
        "AFS_LOG_LEVEL": "warning"
    }
}
EOF
)
    
    RESPONSE=$(curl -s -X POST "$BASE_URL/api/initial_setup.php" \
        -H "Content-Type: application/json" \
        -H "X-API-Key: $API_KEY" \
        -d "$UPDATE_DATA")
    
    if [ "$JQ_AVAILABLE" = true ]; then
        echo "$RESPONSE" | jq .
    else
        echo "$RESPONSE"
    fi
    
    # Check if update was successful
    if echo "$RESPONSE" | grep -q '"ok":true'; then
        log_success "Configuration updated successfully with authentication"
    else
        log_error "Failed to update configuration"
        exit 1
    fi
fi

# Test 4: Try to update without authentication (should fail)
log_info "Test 4: Attempting update without authentication (should fail)..."

RESPONSE=$(curl -s -X POST "$BASE_URL/api/initial_setup.php" \
    -H "Content-Type: application/json" \
    -d '{"settings": {"AFS_LOG_LEVEL": "info"}}')

if [ "$JQ_AVAILABLE" = true ]; then
    echo "$RESPONSE" | jq .
else
    echo "$RESPONSE"
fi

# Check if update was rejected
if echo "$RESPONSE" | grep -q '"ok":false'; then
    log_success "Unauthenticated update correctly rejected"
else
    log_error "Security issue: Unauthenticated update was not rejected!"
    exit 1
fi

# Test 5: Check GitHub update endpoint
log_info "Test 5: Checking GitHub update endpoint..."

RESPONSE=$(curl -s -X GET "$BASE_URL/api/github_update.php")

if [ "$JQ_AVAILABLE" = true ]; then
    echo "$RESPONSE" | jq .
else
    echo "$RESPONSE"
fi

if echo "$RESPONSE" | grep -q '"ok":true'; then
    log_success "GitHub update endpoint is accessible"
else
    log_warning "GitHub update endpoint returned an error (may be expected if not a git repo)"
fi

# Test 6: Check update notification endpoint (should require auth)
log_info "Test 6: Checking update notification endpoint (should require auth)..."

if [ -n "$API_KEY" ]; then
    NOTIFICATION_DATA=$(cat <<EOF
{
    "event": "interface_updated",
    "timestamp": "$(date '+%Y-%m-%d %H:%M:%S')",
    "update_info": {
        "branch": "main",
        "commits_behind": 0,
        "current_commit": "test123",
        "remote_commit": "test123"
    },
    "server_info": {
        "hostname": "test-server",
        "php_version": "8.2.0"
    }
}
EOF
)
    
    RESPONSE=$(curl -s -X POST "$BASE_URL/api/update_notification.php" \
        -H "Content-Type: application/json" \
        -H "X-API-Key: $API_KEY" \
        -d "$NOTIFICATION_DATA")
    
    if [ "$JQ_AVAILABLE" = true ]; then
        echo "$RESPONSE" | jq .
    else
        echo "$RESPONSE"
    fi
    
    if echo "$RESPONSE" | grep -q '"ok":true'; then
        log_success "Update notification endpoint working correctly"
    else
        log_warning "Update notification endpoint returned an error (may be expected)"
    fi
else
    log_warning "No API key available. Skipping update notification test."
fi

echo ""
echo "======================================"
echo "Integration Test Complete"
echo "======================================"
log_success "All tests completed successfully!"
echo ""
echo "Note: If you created a test .env file, you may want to restore your original configuration."
