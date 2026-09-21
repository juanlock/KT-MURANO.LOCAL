@extends('setup.main')
@section('content')

    <p class="eyebrow">Step 3 of 4</p>
    <h1>Database connection</h1>
    <p class="lead-text">Point Stocky at an empty MySQL database. Test the connection before you continue.</p>

    <form id="dbform" action="{{ route('setupStep2') }}" method="post">
        @csrf

        <div id="errormsg"></div>
        <div id="db_settings"></div>

        <div class="form-grid">
            <div class="form-group full">
                <label for="db_connection">Database type</label>
                <span class="tip" title="The database engine Stocky will use"><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                <select class="form-control" id="db_connection" name="db_connection">
                    <option value="mysql">MySQL</option>
                </select>
            </div>

            <div class="form-group">
                <label for="db_host" id="db_host_label">Host</label>
                <span class="tip" id="db1tooltip" title="The IP or domain of your database server. For local development this is usually 127.0.0.1"><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                <input type="text" class="form-control" id="db_host" name="db_host" placeholder="127.0.0.1" required value="{{ $data['DB_HOST'] }}">
            </div>

            <div class="form-group">
                <label for="db_port" id="db_port_label">Port</label>
                <span class="tip" id="db2tooltip" title="The port your database listens on — MySQL default is 3306"><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                <input type="text" class="form-control" id="db_port" name="db_port" placeholder="3306" required value="{{ $data['DB_PORT'] }}">
            </div>

            <div class="form-group full">
                <label for="db_database" id="db_database_label">Database name</label>
                <span class="tip" title="The name of an existing, empty database"><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                <input type="text" class="form-control" id="db_database" name="db_database" placeholder="stocky" required>
            </div>

            <div class="form-group">
                <label for="db_username" id="db_username_label">Username</label>
                <span class="tip" id="db3tooltip" title="A database user with full access to the database above"><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                <input type="text" class="form-control" id="db_username" name="db_username" placeholder="root" required value="{{ $data['DB_USERNAME'] }}">
            </div>

            <div class="form-group">
                <label for="db_password" id="db_password_label">Password</label>
                <span class="tip" id="db4tooltip" title="The password for that database user — leave empty if there is none"><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                <input type="text" class="form-control" id="db_password" name="db_password" placeholder="Password" required value="{{ $data['DB_PASSWORD'] }}">
            </div>
        </div>

        <a id="testdb" class="btn btn-dark text-white"> Test connection <i class="fa fa-plug"></i></a>

        <div class="pane-nav">
            <a href="/setup/step-1" class="btn btn-ghost"><i class="fa fa-angle-left"></i> Back</a>
            <button type="submit" class="btn btn-accent next_step d-none">Continue <i class="fa fa-angle-right"></i></button>
        </div>
    </form>

@endsection
