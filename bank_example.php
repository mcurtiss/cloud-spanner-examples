<?php
namespace Google\Cloud\Samples\Spanner;
use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\KeySet;
use Google\Cloud\Spanner\KeyRange;
use Google\Cloud\Spanner\Transaction;

# Include the autoloader for libraries installed with composer
require __DIR__ . '/vendor/autoload.php';
/*
Uasge:
php bank_example.php
  --instance=[gcloud instance] \
  --database={database name} \

Note: all arguments above are mandatory. It is assumed the the database name does not yet exist on you GCP instance.

*/

# if zero, then don't process/use this table at all.
$AGGREGATE_BALANCE_SHARDS = 16;
class NegativeBalance {
	}
class RowAlreadyUpdated{
	}
class NoResults{
	}
class TooManyResults{
	}
class Unsupported{
	}

function parseCliOptions() {
    global $arrOPERATIONS;
    $longopts = array(
        "instance:",
        "database:",
        );
    $arrParameters = getopt("", $longopts);
    return $arrParameters;
    }



function generate_int64() {
	// Should check at some point that PHP can support such a large number.
	// Since this is used for a "bank account" number, it may behoove us to just generate a string instead.
	return abs(rand(0, (1<<63)-1));
	}

function generate_customer_number() {
	return generate_int64();
	}

function generate_account_number() {
	return generate_int64();
	}

$CUSTOMERS = array();
$ACCOUNTS = array();

for ($i = 0; $i < 5; $i++) {
	$CUSTOMERS[] = generate_customer_number();
	$ACCOUNTS[] = generate_account_number();
	}

function clear_tables($database) {
    $keyset = new KeySet();

    /*
    // You can specify a key for a keyset:
    $keyset->addKey(102030405060708090);
    */

    /* 
    // Or define a range:
    $range = new KeyRange([
        //'startType' => KeyRange::TYPE_OPEN,
        'start' => -8995340435582893004,
        'endType' => KeyRange::TYPE_CLOSED,
        'end' => 9049029653893203423
        ]);
    
    $keyset->addRange($range);
    */

    // But for the sake of this example, we are deleting all data:
    $keyset->setMatchAll(TRUE);
    $results = $database->delete("AccountHistory", $keyset);
    $results = $database->delete("Accounts", $keyset);
    $results = $database->delete("Customers", $keyset);
    $results = $database->delete("AggregateBalance", $keyset);

    /*
    $operation = $database->transaction(['singleUse' => false])
        ->deleteBatch('AccountHistory')
        ->commit();
    $operation = $database->transaction(['singleUse' => false])
        ->deleteBatch('Accounts')
        ->commit();
    $operation = $database->transaction(['singleUse' => false])
        ->deleteBatch('Customers')
        ->commit();
	if ($AGGREGATE_BALANCE_SHARDS > 0) {
		$operation = $database->transaction(['singleUse' => false])
        	->deleteBatch('AggregateBalance')
		->commit();
		}
*/
    }

function setup_customers($database) {
	global $CUSTOMERS;
	global $ACCOUNTS;
	global $AGGREGATE_BALANCE_SHARDS;
	global $spanner;
	clear_tables($database);
	
	$table = "Customers";
	$values = array(
		array('CustomerNumber'=>$CUSTOMERS[0], 'FirstName'=>'Marc', 'LastName'=>'Richards'),
		array('CustomerNumber'=>$CUSTOMERS[1], 'FirstName'=>'Catalina', 'LastName'=>'Smith'),
		array('CustomerNumber'=>$CUSTOMERS[2], 'FirstName'=>'Alice', 'LastName'=>'Trentor'),
		array('CustomerNumber'=>$CUSTOMERS[3], 'FirstName'=>'Lea', 'LastName'=>'Martin'),
		array('CustomerNumber'=>$CUSTOMERS[4], 'FirstName'=>'David', 'LastName'=>'Lomond')
		);
	$operation = $database->transaction(['singleUse' => true])->insertBatch($table, $values)->commit();

	$table = "Accounts";
	$values = array(
		array('CustomerNumber'=>$CUSTOMERS[0], 'AccountNumber'=>$ACCOUNTS[0], 'AccountType'=>0, 'Balance'=>0, 'CreationTime'=>$spanner->timestamp(new \DateTime(date("Y-m-d"), new \DateTimeZone("UTC"))), 'LastInterestCalculation'=>NULL),
		array('CustomerNumber'=>$CUSTOMERS[1], 'AccountNumber'=>$ACCOUNTS[1], 'AccountType'=>1, 'Balance'=>0, 'CreationTime'=>$spanner->timestamp(new \DateTime(date("Y-m-d"), new \DateTimeZone("UTC"))), 'LastInterestCalculation'=>NULL),
		array('CustomerNumber'=>$CUSTOMERS[2], 'AccountNumber'=>$ACCOUNTS[2], 'AccountType'=>0, 'Balance'=>0, 'CreationTime'=>$spanner->timestamp(new \DateTime(date("Y-m-d"), new \DateTimeZone("UTC"))), 'LastInterestCalculation'=>NULL),
		array('CustomerNumber'=>$CUSTOMERS[3], 'AccountNumber'=>$ACCOUNTS[3], 'AccountType'=>1, 'Balance'=>0, 'CreationTime'=>$spanner->timestamp(new \DateTime(date("Y-m-d"), new \DateTimeZone("UTC"))), 'LastInterestCalculation'=>NULL),
		array('CustomerNumber'=>$CUSTOMERS[4], 'AccountNumber'=>$ACCOUNTS[4], 'AccountType'=>0, 'Balance'=>0, 'CreationTime'=>$spanner->timestamp(new \DateTime(date("Y-m-d"), new \DateTimeZone("UTC"))), 'LastInterestCalculation'=>NULL)
		);
	$operation = $database->transaction(['singleUse' => true])->insertBatch($table, $values)->commit();

	$table = "AccountHistory";
	$values = array();
	foreach ($ACCOUNTS as $a) {
		$values[] = array('AccountNumber'=>$a, 'Ts'=>$spanner->timestamp(new \DateTime(date("Y-m-d"), new \DateTimeZone("UTC"))), 'ChangeAmount'=>0, 'Memo'=>'New Account Initial Deposit');
		}
	$operation = $database->transaction(['singleUse' => true])->insertBatch($table, $values)->commit();

	if ($AGGREGATE_BALANCE_SHARDS > 0) {
		$table = 'AggregateBalance';
		$values = array();
		for ($i = 0; $i < $AGGREGATE_BALANCE_SHARDS; $i++) {
			$values[] = array('Shard'=>$i, 'Balance'=>0);
			}
		$operation = $database->transaction(['singleUse' => true])->insertBatch($table, $values)->commit();
		}
	print "Inserted Data.\n";
	}

function extract_single_row_to_array($results) {
	// Originally called tuple, but PHP does not support tuples, only arrays
	foreach ($results as $r) {
		return $r;
		}
	}

function extract_single_cell($results) {
	$r = extract_single_row_to_array($results);
	if (is_array($r)) {
		foreach ($r as $e) {
			return $e;
			}
		}
	else return $r;
	}

function account_balance($database, $account_number) {
	$snapshot = $database->snapshot();
	$results = $snapshot->execute("SELECT Balance FROM Accounts@{FORCE_INDEX=UniqueAccountNumbers} WHERE AccountNumber = $account_number");
	$balance = extract_single_cell($results);
	print "Account Balance: $balance\n";
	return $balance;
	}

function customer_balance($database, $customer_number) {
	$snapshot = $database->snapshot();
	$results = $snapshot->execute("SELECT sum(a.Balance) FROM Accounts a INNER JOIN Customers c ON a.CustomerNumber = c.CustomerNumber WHERE c.CustomerNumber = $customer_number");
	$balance = extract_single_cell($results);
	print "Account Balance: $balance\n";
	return $balance;
	}

function last_n_transactions($database, $account_number, $n) {
	$snapshot = $database->snapshot();
	$results = $snapshot->execute("SELECT Ts, ChargeAmount, Memo FROM Accounts@{FORCE_INDEX=UniqueAccountNumbers} WHERE AccountNumber = $account_number LIMIT $n");
	print implode(", ", $results) . "\n";
	return $results;
	}

function deposit_helper($transaction, $customer_number, $account_number, $cents, $memo, $new_balance) {
	global $AGGREGATE_BALANCE_SHARDS;
	global $spanner;
	$values = ['CustomerNumber'=>$customer_number, "AccountNumber"=>$account_number, "Balance"=>$new_balance];
	$table = "Accounts";
	$transaction->updateBatch($table, [$values,]);
	$table = "AccountHistory";
	$now = $spanner->timestamp(new \DateTime(date("Y-m-d H:i:s.u"), new \DateTimeZone("UTC")));
	$values = ['AccountNumber'=>$account_number, 'Ts'=>$now, 'ChangeAmount'=>$cents, 'Memo'=>$memo];
	$transaction->insertBatch($table, [$values,]);
	if ($AGGREGATE_BALANCE_SHARDS > 0) {
		$shard = rand(0, $AGGREGATE_BALANCE_SHARDS - 1);
		$results = $transaction->execute("SELECT Balance FROM AggregateBalance WHERE Shard = $shard");
		$old_agg_balance = extract_single_cell($results);
		$new_agg_balance = $old_agg_balance + $cents;
		$table = "AggregateBalance";
		$values = array('Shard'=>$shard, 'Balance'=>$new_agg_balance);
		$transaction->updateBatch($table, [$values,]);
		}
	$transaction->commit();
	}

function deposit($database, $customer_number, $account_number, $cents, $memo=NULL) {
	global $spanner;
	$database->runTransaction(function (Transaction $t) use ($spanner, $customer_number, $account_number, $cents, $memo) {
		//$now = $spanner->timestamp(new \DateTime(date("Y-m-d"), new \DateTimeZone("UTC")));
		$results = $t->execute("SELECT Balance From Accounts
			WHERE AccountNumber=$account_number
			AND CustomerNumber=$customer_number");
	   	$old_balance = extract_single_cell($results);
	   	$new_balance = $old_balance + $cents;
		if ($cents < 0 && $new_balance < 0) {
			// Catch Exception for negative balance
			}
		deposit_helper($t, $customer_number, $account_number, $cents, $memo, $new_balance);
		// Need to fix this, for manually throwing an error in PHP.
		//$database->run_in_transaction(deposit_runner);
		print("Transaction complete.\n");
		});
	}
	
	
function compute_interest_for_account($transaction, $customer_number, $account_number, $last_interest_calculation) {
	global $spanner;
	$results = $transaction->execute("SELECT Balance, CURRENT_TIMESTAMP()
					FROM Accounts
    				WHERE CustomerNumber=$customer_number
					AND AccountNumber=$account_number
					AND (LastInterestCalculation IS NULL 
						OR LastInterestCalculation='$last_interest_calculation'");
	list($old_balance, $current_timestamp) = extract_single_row_to_tuple($results);
	if ($old_balance == None || $current_timestamp == None) {
		#throw an exception, RowAlreadyUpdated, for NoResults
		}
		$cents = (int) (0.01 * $old_balance);
		$new_balance = $old_balance + $cents;
		deposit_helper($transaction, $customer_number, $account_number, $cents, 'Monthly Interest', $new_balance, $current_timestamp);
		$values = ['CustomerNumber'=>$customer_number, 
				"AccountNumber"=>$account_number,
				"lastInterestCalculation"=>$current_timestamp];
	    $table = "Accounts";
		$transaction->updateBatch($table, [$values,]);
	}

function compute_interest_for_all($database) {
	$batch_size = 2;
	while (TRUE) {
		$results = $database->execute("SELECT CustomerNumber, AccountNumber, LastInterestCalculation 
			FROM Accounts
    		WHERE LastInterestCalculation IS NULL
			OR (EXTRACT(MONTH FROM LastInterestCalculation) <> EXTRACT(MONTH FROM CURRENT_TIMESTAMP())
			AND EXTRACT(YEAR FROM LastInterestCalculation) <> EXTRACT(YEAR FROM CURRENT_TIMESTAMP()))
    		LIMIT $batch_size");
		$zero_results = TRUE;
		// Try
		foreach ($results as $r) {
			$zero_results = FALSE;
			$database->runTransaction(function (Transaction $t) use ($spanner) {
				compute_interest_for_account($t, 
					$r['CustomerNumber'], 
					$r['AccountNumber'],
					$r['LastInterestCalculation']);
				}); 
				print "Computed interest for account {$r['AccountNumber']}.\n";
				// Needs to execute only if exception "Row already updated."
				print "Account {$r['AccountNumber']} already updated.\n";
			}
		if ($zero_results == TRUE) break;
		}
	}

function verify_consistent_balances($database) {
	global $AGGREGATE_BALANCE_SHARDS;
	if ($AGGREGATE_BALANCE_SHARDS > 0) {
		$balance_slow = extract_single_cell($database->execute("select sum(balance) from Accounts"));
		$balance_fast = extract_single_cell($database->execute("select sum(balance) from AggregateBalance"));
		assert($balance_slow == $balance_fast);
		}
	}

function total_bank_balance($database) {
	global $AGGREGATE_BALANCE_SHARDS;
	if ($AGGREGATE_BALANCE_SHARDS <= 0) {
		print "There is no fast way to compute aggregate balance.";
		exit;
		}
	$results = $database->execute("select sum(balance) from AggregateBalance");
	$balance = extract_single_cell($results);
	print "Total bank balance $balance";
	return $balance;
	}


$arrParameters = parseCliOptions();
//function _main_() {
	$spanner = new SpannerClient();
	$instance = $spanner->instance($arrParameters['instance']);
	$database = $instance->database($arrParameters['database']);
	clear_tables($database);
	setup_customers($database);
	account_balance($database, $ACCOUNTS[1]);
	customer_balance($database, $CUSTOMERS[0]);
	deposit($database, $CUSTOMERS[0], $ACCOUNTS[0], 150, 'Dollar Fifty Deposit');

//	}


?>
