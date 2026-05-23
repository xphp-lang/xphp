// Build script for the xphp PhpStorm plugin.
//
// Uses the IntelliJ Platform Gradle Plugin 2.x DSL -- the successor to the
// legacy `gradle-intellij-plugin`.  The 2.x DSL is the supported path going
// forward and the one JetBrains' own plugin template uses.
//
// All version pins live in gradle.properties so a single edit there propagates
// to every coordinate (since-build, IDE target, kotlin runtime, jvm toolchain).
// Reach them with `providers.gradleProperty("...")` rather than
// `project.findProperty(...)` so Gradle's configuration cache stays happy.

import org.jetbrains.intellij.platform.gradle.IntelliJPlatformType

plugins {
    id("java")
    id("org.jetbrains.kotlin.jvm") version "2.3.21"
    id("org.jetbrains.intellij.platform") version "2.16.0"
}

group = providers.gradleProperty("pluginGroup").get()
version = providers.gradleProperty("pluginVersion").get()

java {
    toolchain {
        languageVersion.set(JavaLanguageVersion.of(providers.gradleProperty("javaVersion").get().toInt()))
    }
}

kotlin {
    jvmToolchain(providers.gradleProperty("javaVersion").get().toInt())
}

// Repositories live here (not in settings.gradle.kts) because the settings-
// level `org.jetbrains.intellij.platform.settings` plugin can't expose its
// `intellijPlatform { defaultRepositories() }` Kotlin accessor reliably --
// see settings.gradle.kts for the longer rationale.  Applying the main
// plugin at the project level via `plugins {}` above gives us the accessor
// here without any of that grief.
repositories {
    mavenCentral()

    intellijPlatform {
        defaultRepositories()
    }
}

dependencies {
    intellijPlatform {
        // PhpStorm matches the LSP-API target window we picked in
        // gradle.properties.  `create(IntelliJPlatformType.PhpStorm, version)`
        // resolves the binary IDE distribution from JetBrains' installers repo
        // (the `jetbrainsIdeInstallers` entry registered by
        // `defaultRepositories()`), not from a Maven artifact -- there is no
        // Maven publication for the full PhpStorm distribution.
        create(
            IntelliJPlatformType.PhpStorm,
            providers.gradleProperty("platformVersion").get(),
        )

        // The bundled PHP plugin gives us the platform PHP language classes
        // (file type registry, PSI, PhpStorm-specific configurables).  Without
        // it our File-Type registration sits alongside PHP's instead of being
        // recognised as a sibling.
        bundledPlugins(providers.gradleProperty("platformBundledPlugins").map { it.split(",") })

        // Toolchain components used by the build / verify pipeline.
        pluginVerifier()
        zipSigner()
    }

    // Plain JUnit 5 for unit-level tests against pure-data classes
    // (XphpLanguage, XphpFileType).  We deliberately do NOT pull in
    // `intellijPlatform { testFramework(TestFrameworkType.Platform) }`
    // here -- that injects IntelliJ's `PathClassLoader` over the test
    // runtime, which breaks plain JUnit 5 dispatch.  Anything that
    // genuinely needs `BasePlatformTestCase` should live in a separate
    // source set with its own test task; the build verifier already
    // covers structural plugin validation without booting the IDE.
    testImplementation("org.junit.jupiter:junit-jupiter:5.11.4")
    testRuntimeOnly("org.junit.platform:junit-platform-launcher:1.11.4")
}

intellijPlatform {
    pluginConfiguration {
        version.set(providers.gradleProperty("pluginVersion"))

        ideaVersion {
            sinceBuild.set(providers.gradleProperty("pluginSinceBuild"))
            untilBuild.set(providers.gradleProperty("pluginUntilBuild"))
        }
    }

    pluginVerification {
        ides {
            // Verify against the same baseline we target.  Future minors get
            // added when they ship; the until-build window catches the rest.
            recommended()
        }
    }
}

// Bring the TextMate grammar from tools/lsp/vscode-extension/syntaxes/ into
// the plugin resources at build time -- single source of truth across the
// VS Code extension and this plugin.  The grammar isn't wired to a TextMate
// bundle yet (chunk 3 registers the file type only; LSP semantic tokens from
// chunk 4 cover highlighting), but bundling it now means the resource exists
// when a later chunk plugs it in.
//
// Wired straight into `processResources` via `from(...)` rather than as a
// finalised separate Copy task -- Gradle 9's strict input/output validation
// flags any indirect path from copy output to the `jar` task's input
// directory, and adding the source here makes the dependency explicit by
// construction.
val tmLanguageSource = file("../lsp/vscode-extension/syntaxes/xphp.tmLanguage.json")

// PHAR built by `make -C tools/lsp build/phar`.  Bundled into the plugin jar
// at `bin/xphp-lsp.phar`; PharExtractor reads it from the classpath on
// first plugin load and copies it into PhpStorm's system directory.
//
// Same shape as the tmLanguage copy above: upstream artifact, build it
// out-of-band and we pick it up.  CI runs both makes before assembling the
// plugin (chunk 6).  A missing PHAR doesn't fail the build -- it ships an
// LSP-less plugin where the user must point Settings -> Tools -> xPHP at
// an external binary.  That's the right behaviour for a dev iterating on
// the plugin without rebuilding the LSP on every change.
val xphpLspPhar = file("../lsp/var/xphp-lsp.phar")

tasks {
    processResources {
        if (tmLanguageSource.exists()) {
            from(tmLanguageSource) {
                into("textmate")
            }
        } else {
            doFirst {
                logger.warn(
                    "TextMate grammar missing at ${tmLanguageSource.path} -- " +
                        "shipping plugin without it.  The VS Code extension's " +
                        "syntaxes/xphp.tmLanguage.json is the source of truth; " +
                        "regenerate / restore it there if you need this in the jar."
                )
            }
        }

        if (xphpLspPhar.exists()) {
            from(xphpLspPhar) {
                into("bin")
            }
        } else {
            doFirst {
                logger.warn(
                    "Bundled xphp-lsp.phar missing at ${xphpLspPhar.path} -- " +
                        "shipping plugin WITHOUT a bundled LSP binary.  Users " +
                        "will need to set Preferences -> Tools -> xPHP -> 'xphp " +
                        "LSP binary' to an external path before .xphp editing " +
                        "intelligence is available.  Run `make -C tools/lsp " +
                        "build/phar` before `./gradlew build` to produce a " +
                        "self-contained plugin."
                )
            }
        }
    }

    test {
        useJUnitPlatform()
    }

    // Pre-empt the IntelliJ Platform Gradle Plugin's tendency to download a
    // brand-new JBR every clean -- pin to the bundled toolchain when CI runs.
    wrapper {
        gradleVersion = "9.0.0"
    }
}
