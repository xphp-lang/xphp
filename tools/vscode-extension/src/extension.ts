import * as path from "node:path";
import * as fs from "node:fs";
import {
    ExtensionContext,
    workspace,
    window,
    OutputChannel,
} from "vscode";
import {
    LanguageClient,
    LanguageClientOptions,
    ServerOptions,
    TransportKind,
} from "vscode-languageclient/node";

let client: LanguageClient | undefined;

export async function activate(context: ExtensionContext): Promise<void> {
    const output: OutputChannel = window.createOutputChannel("xphp Language Server");
    context.subscriptions.push(output);

    const serverPath = resolveServerPath(output);
    if (!serverPath) {
        output.appendLine(
            "xphp-lsp binary not found. Configure xphp.serverPath in settings or place the extension alongside tools/lsp/bin/xphp-lsp."
        );
        return;
    }

    const phpPath = workspace.getConfiguration("xphp").get<string>("phpPath", "php");

    // PHP runs the LSP server over stdio; vscode-languageclient handles the
    // JSON-RPC framing. TransportKind.stdio is the default and matches
    // LanguageServerBuilder's default in the PHP server.
    const serverOptions: ServerOptions = {
        command: phpPath,
        args: [serverPath],
        transport: TransportKind.stdio,
    };

    const clientOptions: LanguageClientOptions = {
        documentSelector: [
            { scheme: "file", language: "xphp" },
        ],
        // Surface server stderr to the output channel for debugging — phpactor
        // logs go here too, plus any deprecation noise the server didn't mute.
        outputChannel: output,
    };

    client = new LanguageClient(
        "xphp",
        "xphp Language Server",
        serverOptions,
        clientOptions,
    );

    try {
        await client.start();
        output.appendLine(`xphp-lsp started: ${phpPath} ${serverPath}`);
    } catch (err) {
        output.appendLine(`xphp-lsp failed to start: ${String(err)}`);
        window.showErrorMessage(
            "xphp-lsp failed to start; see the 'xphp Language Server' output channel for details."
        );
    }
}

export async function deactivate(): Promise<void> {
    if (client) {
        await client.stop();
        client = undefined;
    }
}

function resolveServerPath(output: OutputChannel): string | undefined {
    const configured = workspace.getConfiguration("xphp").get<string>("serverPath", "");
    if (configured && configured.trim() !== "") {
        return configured;
    }

    // Convention: when the extension is loaded from inside this repo (the F5
    // dev workflow), the server sits at the sibling tools/lsp/bin/xphp-lsp.
    // `__dirname` at runtime points at tools/vscode-extension/out, so walking
    // up two levels lands at tools/, then we step into lsp/bin/.  When the
    // extension is installed standalone, the user must set xphp.serverPath
    // explicitly.  We try the in-repo path first and fall back to the
    // workspace's tools/lsp directory.
    const candidates = [
        path.resolve(__dirname, "..", "..", "lsp", "bin", "xphp-lsp"),
        ...(workspace.workspaceFolders ?? []).map((folder) =>
            path.join(folder.uri.fsPath, "tools", "lsp", "bin", "xphp-lsp"),
        ),
    ];
    for (const candidate of candidates) {
        if (fs.existsSync(candidate)) {
            return candidate;
        }
    }
    output.appendLine(
        `Searched for xphp-lsp at:\n  ${candidates.join("\n  ")}`,
    );
    return undefined;
}
