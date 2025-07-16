<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

// Error Handling
ini_set('display_errors', 1);
error_reporting(E_ALL);

// If already connected, proceed to the dashboard
if (isset($_SESSION['db_host'], $_SESSION['db_user'], $_SESSION['db_name'])) {
    $conn = new mysqli($_SESSION['db_host'], $_SESSION['db_user'], $_SESSION['db_pass'], $_SESSION['db_name']);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
}


// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Handle form submission for database connection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['host'], $_POST['user'], $_POST['dbname'])) {
    $conn = @new mysqli($_POST['host'], $_POST['user'], $_POST['pass'], $_POST['dbname']);
    if ($conn->connect_error) {
        $error = "Connection failed: " . $conn->connect_error;
    } else {
        $_SESSION['db_host'] = $_POST['host'];
        $_SESSION['db_user'] = $_POST['user'];
        $_SESSION['db_pass'] = $_POST['pass'];
        $_SESSION['db_name'] = $_POST['dbname'];
        header('Location: index.php');
        exit;
    }
}

// Function to escape data for safety
function escape($data) {
    global $conn;
    return htmlspecialchars($conn->real_escape_string($data));
}

// Handle AJAX request
if (isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'list_tables') {
        $result = $conn->query("SHOW TABLES");
        $tables = [];
        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }
        echo json_encode(['tables' => $tables]);
        exit;
    }

    if ($action === 'table_info' && isset($_POST['table'])) {
        $table = escape($_POST['table']);
        
        // Get table structure
        $structure = $conn->query("DESCRIBE `$table`");
        $fields = [];
        while ($row = $structure->fetch_assoc()) {
            $fields[] = $row;
        }
        
        // Get row count
        $count_result = $conn->query("SELECT COUNT(*) as count FROM `$table`");
        $count = $count_result->fetch_assoc()['count'];
        
        echo json_encode(['fields' => $fields, 'count' => $count]);
        exit;
    }

    if ($action === 'load_table' && isset($_POST['table'])) {
        $table = escape($_POST['table']);
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $limit = 25;
        $offset = ($page - 1) * $limit;
        
        // Get total count
        $count_result = $conn->query("SELECT COUNT(*) as count FROM `$table`");
        $total_rows = $count_result->fetch_assoc()['count'];
        $total_pages = ceil($total_rows / $limit);
        
        $res = $conn->query("SELECT * FROM `$table` LIMIT $limit OFFSET $offset");
        $output = '<div class="table-container">';
        $output .= '<div class="table-header">Table: <strong>' . $table . '</strong> (' . $total_rows . ' rows)</div>';
        $output .= '<table class="data-table"><thead><tr>';
        
        $fields = [];
        while ($field = $res->fetch_field()) {
            $fields[] = $field->name;
            $output .= "<th>{$field->name}</th>";
        }
        //$output .= '<th>Actions</th>';
        $output .= '</tr></thead><tbody>';

        $row_num = $offset + 1;
        while ($row = $res->fetch_assoc()) {
            $output .= "<tr>";
            foreach ($row as $val) {
                $escaped = htmlspecialchars($val ?? '', ENT_QUOTES, 'UTF-8');
                // Apply a class to the td for styling long text
                $output .= "<td class='data-cell'>" . $escaped . "</td>";
            }
            //$output .= "<td><button class='btn btn-sm btn-primary edit-row' data-table='$table' data-row='$row_num'>Edit</button> ";
            //$output .= "<button class='btn btn-sm btn-danger delete-row' data-table='$table' data-row='$row_num'>Delete</button></td>";
            $output .= "</tr>";
            $row_num++;
        }
        $output .= '</tbody></table>';
        
        // Pagination
        if ($total_pages > 1) {
            $output .= '<div class="pagination">';
            if ($page > 1) {
                $output .= '<button class="btn btn-secondary" onclick="loadTable(\'' . $table . '\', ' . ($page - 1) . ')">Previous</button>';
            }
            $output .= '<span>Page ' . $page . ' of ' . $total_pages . '</span>';
            if ($page < $total_pages) {
                $output .= '<button class="btn btn-secondary" onclick="loadTable(\'' . $table . '\', ' . ($page + 1) . ')">Next</button>';
            }
            $output .= '</div>';
        }
        
        $output .= '</div>';
        echo $output;
        exit;
    }

    if ($action === 'run_query' && isset($_POST['query'])) {
        $query = $_POST['query'];
        $start_time = microtime(true);
        $res = $conn->query($query);
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);

        if ($res === TRUE) {
            echo '<div class="alert alert-success">Query executed successfully. Execution time: ' . $execution_time . ' ms</div>';
        } elseif ($res instanceof mysqli_result) {
            $output = '<div class="query-result">';
            $output .= '<div class="query-info">Rows returned: ' . $res->num_rows . ' | Execution time: ' . $execution_time . ' ms</div>';
            $output .= '<table class="data-table"><thead><tr>';
            
            $fields = [];
            while ($field = $res->fetch_field()) {
                $fields[] = $field->name;
                $output .= "<th>{$field->name}</th>";
            }
            $output .= '</tr></thead><tbody>';
            
            while ($row = $res->fetch_assoc()) {
                $output .= "<tr>";
                foreach ($row as $val) {
                    $escaped = htmlspecialchars($val ?? '', ENT_QUOTES, 'UTF-8');
                    // Apply a class to the td for styling long text in query results
                    $output .= "<td class='data-cell'>" . $escaped . "</td>";
                }
                $output .= "</tr>";
            }
            $output .= '</tbody></table></div>';
            echo $output;
        } else {
            echo '<div class="alert alert-danger">Error: ' . $conn->error . '</div>';
        }
        exit;
    }

    if ($action === 'export_table' && isset($_POST['table'])) {
        $table = escape($_POST['table']);
        $res = $conn->query("SELECT * FROM `$table`");
        
        header('Content-Type: application/csv');
        header('Content-Disposition: attachment; filename="' . $table . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Write header
        $fields = [];
        while ($field = $res->fetch_field()) {
            $fields[] = $field->name;
        }
        fputcsv($output, $fields);
        
        // Write data
        while ($row = $res->fetch_assoc()) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }

    if ($action === 'drop_table' && isset($_POST['table'])) {
        $table = escape($_POST['table']);
        $res = $conn->query("DROP TABLE `$table`");
        
        if ($res) {
            echo json_encode(['success' => true, 'message' => 'Table dropped successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        exit;
    }

    if ($action === 'get_db_info') {
        $info = [];
        
        // Get database size
        $size_result = $conn->query("SELECT 
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'DB Size in MB'
            FROM information_schema.tables 
            WHERE table_schema = '{$_SESSION['db_name']}'");
        $info['size'] = $size_result->fetch_assoc()['DB Size in MB'];
        
        // Get table count
        $table_result = $conn->query("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = '{$_SESSION['db_name']}'");
        $info['table_count'] = $table_result->fetch_assoc()['count'];
        
        // Get MySQL version
        $version_result = $conn->query("SELECT VERSION() as version");
        $info['mysql_version'] = $version_result->fetch_assoc()['version'];
        
        echo json_encode($info);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHPMyAdmin Lite</title>
    <link rel="stylesheet" type="text/css" href="//cdn.datatables.net/2.3.2/css/dataTables.dataTables.min.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #17a2b8;
            --light-gray: #f8f9fa;
            --dark-gray: #6c757d;
            --border-color: #dee2e6;
            --text-color: #495057;
            --bg-color: #ffffff;
            --header-height: 80px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light-gray);
            color: var(--text-color);
            line-height: 1.6;
            font-size: 0.9rem; /* Slightly smaller base font for compactness */
        }

        .header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            height: var(--header-height);
            display: flex;
            padding: 0 2rem; /* Adjusted padding */
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header h1 {
            font-size: 1.6rem; /* Slightly smaller heading */
            font-weight: 300;
        }

        .header .db-info {
            font-size: 0.8rem; /* Smaller info text */
            opacity: 0.9;
        }

        .logout-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 0.4rem 0.8rem; /* Smaller button padding */
            border-radius: 5px;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 0.9rem; /* Smaller font for button */
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
            text-decoration: none;
        }

        .login-container {
            max-width: 400px;
            margin: 10vh auto;
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .login-container h2 {
            text-align: center;
            margin-bottom: 2rem;
            color: var(--primary-color);
        }

        .form-group {
            margin-bottom: 1rem; /* Reduced margin */
        }

        .form-group label {
            display: block;
            margin-bottom: 0.4rem; /* Reduced margin */
            font-weight: 500;
            color: var(--text-color);
            font-size: 0.9rem; /* Smaller label font */
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem; /* Reduced padding */
            border: 1px solid var(--border-color); /* Thinner border */
            border-radius: 5px;
            font-size: 0.9rem; /* Smaller input font */
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .btn {
            display: inline-block;
            padding: 0.6rem 1.2rem; /* Reduced padding */
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem; /* Smaller button font */
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s;
            text-align: center;
        }

        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #2980b9;
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background-color: #229954;
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c0392b;
        }

        .btn-secondary {
            background-color: var(--dark-gray);
            color: white;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        .btn-sm {
            padding: 0.3rem 0.6rem; /* Even smaller for small buttons */
            font-size: 0.8rem;
        }

        .btn-block {
            width: 100%;
        }

        .container {
            display: flex;
            min-height: calc(100vh - var(--header-height));
        }

        .sidebar {
            width: 200px; /* Reduced sidebar width */
            min-width: 200px;
            max-width: 300px;
            background: white;
            border-right: 1px solid var(--border-color);
            padding: 1rem;
            overflow-y: auto;
            flex-shrink: 0;
            font-size: 0.9rem; /* Smaller font in sidebar */
        }

        .sidebar h3 {
            color: var(--primary-color);
            margin-bottom: 0.8rem; /* Reduced margin */
            font-size: 1.1rem; /* Slightly smaller heading */
        }

        .table-list {
            list-style: none;
            padding: 0;
            margin: 0;
            height: 70vh;
            overflow-y: auto;
        }

        .table-link {
            display: block;
            padding: 0.5rem 0.75rem; /* Reduced padding */
            background: var(--light-gray);
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            border-bottom: 1px solid rgba(0,0,0,0.05); /* Subtle separator */
        }

        .table-link:hover {
            background: var(--secondary-color);
            color: white;
            text-decoration: none;
            white-space: normal; /* Allow wrap on hover for full table name */
            overflow: visible;
            text-overflow: clip;
        }

        .table-link.active {
            background: var(--secondary-color);
            color: white;
            white-space: normal;
            overflow: visible;
            text-overflow: clip;
        }

        .main-content {
            flex: 1;
            min-width: 0;
            padding: 1.5rem; /* Reduced padding */
            background: white;
            overflow-x: auto;
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color); /* Thinner border */
            margin-bottom: 1.5rem; /* Reduced margin */
        }

        .tab {
            padding: 0.8rem 1.5rem; /* Reduced padding */
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.9rem; /* Smaller tab font */
            color: var(--text-color);
            transition: all 0.3s;
        }

        .tab.active {
            color: var(--secondary-color);
            border-bottom: 2px solid var(--secondary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }
        
        /* Table compact and overflow handling */
        .table-container {
            width: 100%;
            overflow-x: auto; /* Allows horizontal scrolling if content is too wide */
            margin-top: 1rem;
        }

        .data-table {
            margin-top:20px;
            width: 100%;
            border-collapse: collapse;
            border: 1px solid var(--border-color);
            background: white;
        }

        .data-table th,
        .data-table td {
            padding: 0.6rem; /* Reduced padding for cells */
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.85rem; /* Smaller font for table data */
        }

        .data-table th {
            background: var(--light-gray);
            font-weight: 600;
            color: var(--primary-color);
        }

        .data-table .data-cell {
            white-space: nowrap;      /* Prevent text wrapping */
            overflow: hidden;         /* Hide overflowing content */
            text-overflow: ellipsis;  /* Show ellipsis for truncated text */
            max-width: 150px;         /* Optional: Set a max-width for data cells to encourage ellipsis */
        }

        .data-table tr:hover {
            background: rgba(52, 152, 219, 0.1);
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-top: 1rem;
            padding: 0.8rem;
            font-size: 0.9rem;
        }

        .query-editor {
            margin-bottom: 1.5rem;
        }

        .query-textarea {
            width: 100%;
            height: 150px; /* Reduced height */
            font-family: 'Courier New', monospace;
            font-size: 13px; /* Smaller font for textarea */
            resize: vertical;
        }

        .query-actions {
            margin-top: 0.8rem; /* Reduced margin */
            display: flex;
            gap: 0.8rem;
        }

        .alert {
            padding: 0.8rem; /* Reduced padding */
            border-radius: 5px;
            margin-bottom: 0.8rem;
            font-size: 0.9rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .query-result {
            margin-top: 1.5rem;
        }

        .query-info {
            background: var(--light-gray);
            padding: 0.6rem; /* Reduced padding */
            border-radius: 5px;
            margin-bottom: 0.8rem;
            font-size: 0.85rem;
            color: var(--text-color);
        }

        .db-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); /* Slightly smaller cards */
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: aliceblue;
            padding: 1rem; /* Reduced padding */
            border-radius: 8px; /* Slightly smaller radius */
            box-shadow: 0 2px 8px rgba(0,0,0,0.08); /* Lighter shadow */
            text-align: center;
        }

        .stat-card h3 {
            color: var(--primary-color);
            margin-bottom: 0.4rem;
            font-size: 1rem; /* Smaller heading */
        }

        .stat-card .value {
            font-size: 1.7rem; /* Smaller value font */
            font-weight: bold;
            color: var(--secondary-color);
        }

        .table-actions {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: white;
            margin: 10% auto; /* Adjusted margin to be higher */
            padding: 1.5rem; /* Reduced padding */
            border-radius: 8px; /* Slightly smaller radius */
            width: 90%;
            max-width: 450px; /* Reduced max-width */
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 24px; /* Smaller close button */
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: black;
        }

        .structure-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .structure-table th,
        .structure-table td {
            padding: 0.4rem; /* Reduced padding */
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.85rem; /* Smaller font */
        }

        .structure-table th {
            background: var(--light-gray);
            font-weight: 600;
        }
        .flex{
            display:flex;
            align-items:center;
            justify-content: space-between;
        }
        #overview h2{
            margin-bottom:20px;
        }

        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                max-width: 100%; /* Allow sidebar to take full width */
                order: 2;
                border-right: none;
                border-top: 1px solid var(--border-color); /* Add top border for separation */
            }
            
            .main-content {
                order: 1;
                padding: 1rem; /* Even more reduced padding for small screens */
            }

            .header {
                flex-direction: column;
                height: auto;
                padding: 1rem;
                text-align: center;
            }

            .header .db-info {
                margin-top: 0.5rem;
            }

            .logout-btn {
                margin-top: 1rem;
            }
        }
    </style>
</head>
<body>

<?php if (!isset($_SESSION['db_host'])): ?>
    <div class="login-container">
        <h2>Connect to Database</h2>
        <?php if (isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>
        <form method="POST">
            <div class="form-group">
                <label for="host">Host</label>
                <input type="text" id="host" name="host" class="form-control" value="localhost" required>
            </div>
            <div class="form-group">
                <label for="user">Username</label>
                <input type="text" id="user" name="user" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="pass">Password</label>
                <input type="password" id="pass" name="pass" class="form-control">
            </div>
            <div class="form-group">
                <label for="dbname">Database Name</label>
                <input type="text" id="dbname" name="dbname" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Connect</button>
        </form>
    </div>
<?php else: ?>
    <div class="header">
        <div>
            <h1>PHPMyAdmin Lite</h1>
            <div class="db-info">
                Connected to: <strong><?php echo $_SESSION['db_name']; ?></strong> 
                @ <?php echo $_SESSION['db_host']; ?>
            </div>
        </div>
        <a href="?logout=1" class="logout-btn">Logout</a>
    </div>

    <div class="container">
        <div class="sidebar">
            <ul id="tableList" class="table-list"></ul>
            
            <div id="tableInfo" style="margin-top: 2rem;"></div>
        </div>

        <div class="main-content">
            <div class="tabs">
                <button class="tab active" onclick="showTab('overview', this)">Overview</button>
                <button class="tab" onclick="showTab('browse', this)">Browse</button>
                <button class="tab" onclick="showTab('sql', this)">SQL</button>
                <button class="tab" onclick="showTab('structure', this)">Structure</button>
            </div>

            <div id="overview" class="tab-content active">
                <h2>Database Overview</h2>
                <div id="dbStats" class="db-stats">
                    <div class="stat-card">
                        <h3>Tables</h3>
                        <div class="value" id="tableCount">-</div>
                    </div>
                    <div class="stat-card">
                        <h3>Database Size</h3>
                        <div class="value" id="dbSize">-</div>
                    </div>
                    <div class="stat-card">
                        <h3>MySQL Version</h3>
                        <div class="value" id="mysqlVersion">-</div>
                    </div>
                </div>
            </div>

            <div id="browse" class="tab-content">
                <div class="flex">
                    <h2>Browse Table Data</h2>
                    <div class="table-actions">
                        <button class="btn btn-success" onclick="exportTable()">Export CSV</button>
                        <?php /*<button class="btn btn-danger" onclick="confirmDropTable()">Drop Table</button>*/?>
                    </div>
                </div>
                <div id="tableData">Select a table from the sidebar to view its data</div>
            </div>

            <div id="sql" class="tab-content">
                <h2>SQL Query Editor</h2>
                <div class="query-editor">
                    <textarea id="sqlQuery" class="form-control query-textarea" placeholder="Enter your SQL query here..."></textarea>
                    <div class="query-actions">
                        <button id="runQuery" class="btn btn-primary">Run Query</button>
                        <button onclick="clearQuery()" class="btn btn-secondary">Clear</button>
                    </div>
                </div>
                <div id="queryResult"></div>
            </div>

            <div id="structure" class="tab-content">
                <h2>Table Structure</h2>
                <div id="tableStructure">Select a table from the sidebar to view its structure</div>
            </div>
        </div>
    </div>
<?php endif ?>

    <div id="confirmModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3 id="modalTitle">Confirm Action</h3>
            <p id="modalMessage">Are you sure?</p>
            <div style="margin-top: 1rem; text-align: right;">
                <button id="confirmBtn" class="btn btn-danger">Confirm</button>
                <button onclick="closeModal()" class="btn btn-secondary">Cancel</button>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="//cdn.datatables.net/2.3.2/js/dataTables.min.js"></script>
    <script>
        let currentTable = '';
        let currentPage = 1;
        // DataTables isn't strictly necessary for the fixed-layout, text-overflow CSS.
        // If you want to continue using it, initialize it on the actual table element.
        // For dynamic content, you'll need to re-initialize or destroy/recreate.
        // let table; // Commenting this out for now as it's not used consistently with current dynamic table loading

        function showTab(tabName, clickedTab) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            if (clickedTab) {
                clickedTab.classList.add('active');
            }
        }

        function loadTables() {
            $.post('index.php', { action: 'list_tables' }, function (data) {
                let html = '';
                data.tables.forEach(function(table) {
                    html += `<li><a href="#" class="table-link" data-table="${table}">${table}</a></li>`;
                });
                $('#tableList').html(html);
                // No need to initialize DataTable here, as loadTable handles it for content
            }, 'json');
        }

        function loadDatabaseInfo() {
            $.post('index.php', { action: 'get_db_info' }, function (data) {
                $('#tableCount').text(data.table_count);
                $('#dbSize').text(data.size + ' MB');
                $('#mysqlVersion').text(data.mysql_version);
            }, 'json');
        }

        function loadTableInfo(table) {
            $.post('index.php', { action: 'table_info', table: table }, function (data) {
                let html = '<h4>Table Info</h4>';
                html += '<p><strong>Rows:</strong> ' + data.count + '</p>';
                html += '<p><strong>Columns:</strong> ' + data.fields.length + '</p>';
                $('#tableInfo').html(html);
                
                // Load structure
                let structureHtml = '<h3>Structure: ' + table + '</h3>';
                structureHtml += '<table class="structure-table">';
                structureHtml += '<thead><tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>';
                structureHtml += '<tbody>';
                data.fields.forEach(function(field) {
                    structureHtml += '<tr>';
                    structureHtml += '<td>' + field.Field + '</td>';
                    structureHtml += '<td>' + field.Type + '</td>';
                    structureHtml += '<td>' + field.Null + '</td>';
                    structureHtml += '<td>' + field.Key + '</td>';
                    structureHtml += '<td>' + (field.Default || '') + '</td>';
                    structureHtml += '<td>' + field.Extra + '</td>';
                    structureHtml += '</tr>';
                });
                structureHtml += '</tbody></table>';
                $('#tableStructure').html(structureHtml);
            }, 'json');
        }

        function loadTable(table, page = 1) {
            currentTable = table;
            currentPage = page;
            
            // Update active table link
            $('.table-link').removeClass('active');
            $(`[data-table="${table}"]`).addClass('active');
            
            $('#tableData').html('<div class="alert alert-info">Loading...</div>');
            
            $.post('index.php', { 
                action: 'load_table', 
                table: table, 
                page: page 
            }, function (data) {
                $('#tableData').html(data);
                // If you intend to use DataTables for the browse data tab, 
                // you would initialize it *here* after the HTML is loaded.
                // For example:
                // if ($.fn.DataTable.isDataTable('.data-table')) {
                //    $('.data-table').DataTable().destroy();
                // }
                // $('.data-table').DataTable({
                //    paging: false, // You have custom pagination, so disable DataTables' paging
                //    searching: false, // Disable DataTables' searching
                //    info: false // Disable DataTables' info text
                // });
            });
            
            loadTableInfo(table);
        }

        function exportTable() {
            if (!currentTable) {
                alert('Please select a table first');
                return;
            }
            
            // Create a form to submit for CSV download
            $('<form>', {
                method: 'POST',
                action: 'index.php'
            }).append($('<input>', {
                type: 'hidden',
                name: 'action',
                value: 'export_table'
            })).append($('<input>', {
                type: 'hidden',
                name: 'table',
                value: currentTable
            })).appendTo('body').submit().remove();
        }

        function confirmDropTable() {
            if (!currentTable) {
                alert('Please select a table first');
                return;
            }
            
            $('#modalTitle').text('Drop Table');
            $('#modalMessage').text(`Are you sure you want to drop the table "${currentTable}"? This action cannot be undone.`);
            $('#confirmBtn').off('click').on('click', function() {
                dropTable(currentTable);
            });
            $('#confirmModal').show();
        }

        function dropTable(table) {
            $.post('index.php', { 
                action: 'drop_table', 
                table: table 
            }, function (data) {
                if (data.success) {
                    $('#tableData').html('<div class="alert alert-success">' + data.message + '</div>');
                    $('#tableInfo').html('');
                    $('#tableStructure').html('Select a table from the sidebar to view its structure');
                    currentTable = '';
                    loadTables(); // Reload table list after dropping a table
                } else {
                    $('#tableData').html('<div class="alert alert-danger">' + data.message + '</div>');
                }
                closeModal();
            }, 'json');
        }

        function closeModal() {
            $('#confirmModal').hide();
        }

        function clearQuery() {
            $('#sqlQuery').val('');
            $('#queryResult').html('');
        }

        $(document).on('click', '.table-link', function (e) {
            e.preventDefault();
            const table = $(this).data('table');
            loadTable(table);
            // Manually activate the 'Browse' tab button when a table is clicked
            $('.tab-content').removeClass('active');
            $('.tab').removeClass('active');
            $('button:contains("Browse")').addClass('active');
            $('#browse').addClass('active');
        });

        $('#runQuery').on('click', function () {
            const query = $('#sqlQuery').val().trim();
            if (!query) {
                alert('Please enter a SQL query');
                return;
            }
            
            $('#queryResult').html('<div class="alert alert-info">Executing query...</div>');
            
            $.post('index.php', { 
                action: 'run_query', 
                query: query 
            }, function (data) {
                $('#queryResult').html(data);
            });
        });

        // Initial loads when the page is ready
        $(document).ready(function() {
            loadTables();
            loadDatabaseInfo();
            // Call showTab for 'overview' on initial load to set the active state correctly
            showTab('overview', document.querySelector('.tabs .tab.active'));
        });
    </script>
</body>
</html>